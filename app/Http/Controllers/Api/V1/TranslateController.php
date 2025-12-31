<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SQLValidationException;
use App\Http\Controllers\Controller;
use App\Models\SchemaTable;
use App\Models\User;
use App\Services\TogetherAIService;
use App\Services\SQLValidationService;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class TranslateController extends Controller
{
    use ApiResponser;

    public function __construct(
        protected TogetherAIService    $togetherAIService,
        protected SQLValidationService $sqlValidationService
    )
    {
    }

    public function translate(Request $request)
    {
        // 1. Validar la petición (Estructura Unificada "tables")
        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'conversation_id' => 'nullable|string|max:255',

            // Nueva estructura consolidada
            'tables' => 'required|array|min:1',
            'tables.*.id' => 'required|integer|exists:schema_tables,id',
            'tables.*.full_schema' => 'nullable|boolean',
            'tables.*.columns' => 'nullable|array',
            'tables.*.columns.*' => 'string',
        ]);

        $user = $request->user();
        $userQuestion = $validated['question'];
        $conversationId = $validated['conversation_id'] ?? (string)Str::uuid();

        // 2. Extraer IDs y Configuración desde 'tables'
        $tablesInput = collect($validated['tables']);
        $tableIds = $tablesInput->pluck('id')->all();
        $configMap = $tablesInput->keyBy('id'); // Mapa para acceso rápido: [16 => {...config...}]

        // Variables para log y auditoría
        $sqlQuery = null;
        $aiResponseString = '';
        $usageData = [];
        $schemaContextLog = null;
        $dialect = null;

        try {
            // 3. Cargar Tablas desde la BD
            $tablesToLoad = SchemaTable::with('schema')
                ->whereIn('id', $tableIds)
                ->get();

            if ($tablesToLoad->isEmpty()) {
                throw new Exception('No se encontraron las tablas solicitadas.', Response::HTTP_NOT_FOUND);
            }

            // Validar propiedad del esquema
            $schema = $tablesToLoad->first()->schema;
            if ((int)$user->id !== (int)$schema->user_id) {
                return $this->sendError('Acceso no autorizado al esquema de datos.', Response::HTTP_FORBIDDEN);
            }

            $dialect = $schema->dialect;
            $dbPrefix = $schema->database_name_prefix;

            // 4. OPTIMIZACIÓN, FILTRADO Y ORDENAMIENTO
            $filteredTables = $tablesToLoad->map(function ($table) use ($configMap) {
                $tableClone = clone $table;

                // Obtener configuración específica de esta tabla
                $config = $configMap->get($table->id);

                // A. Si pidieron full_schema explícitamente, retornamos todo sin filtrar
                if (!empty($config['full_schema']) && $config['full_schema'] === true) {
                    return $tableClone;
                }

                $requestedColumns = $config['columns'] ?? [];

                if (is_array($tableClone->column_metadata)) {
                    $metaCollection = collect($tableClone->column_metadata);

                    // B. Filtrar Columnas
                    $filteredCollection = $metaCollection->filter(function ($meta) use ($requestedColumns) {
                        // Caso 1: Lista blanca explícita (viene del @ en el front)
                        if (!empty($requestedColumns)) {
                            return in_array($meta['col'], $requestedColumns);
                        }
                        // Caso 2: Defaults (usuario no especificó columnas)
                        return isset($meta['is_default']) && $meta['is_default'] === true;
                    });

                    // C. ORDENAR: Si hay columnas explícitas, respetar el orden visual del usuario
                    if (!empty($requestedColumns)) {
                        $filteredCollection = $filteredCollection->sortBy(function ($meta) use ($requestedColumns) {
                            return array_search($meta['col'], $requestedColumns);
                        });
                    }

                    $tableClone->column_metadata = $filteredCollection->values()->all();
                }

                return $tableClone;
            });

            // 5. Preparar Contexto y Llamar a la IA
            $schemaTablesObjects = $filteredTables->all();

            // Generamos el contexto aquí para guardarlo en el historial
            $schemaContextLog = $this->togetherAIService->getSchemaContext($schemaTablesObjects);

            $serviceResponse = $this->togetherAIService->generateSql(
                $userQuestion,
                $dialect,
                $schemaTablesObjects,
                $dbPrefix,
                $user,
                $conversationId
            );

            $aiResponseString = $serviceResponse['sql_or_error'];
            $usageData = $serviceResponse['usage'];

            // 6. Gestión de Tokens
            DB::transaction(function () use ($user, $usageData) {
                $user->increment('monthly_requests_count');
                if (isset($usageData['total_tokens'])) {
                    $user->increment('monthly_token_count', $usageData['total_tokens']);
                }
            });

            // 7. Procesar Respuesta
            $aiData = json_decode($aiResponseString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match('/\{.*\}/s', $aiResponseString, $matches)) {
                    $aiData = json_decode($matches[0], true);
                }
            }

            if (!$aiData) {
                throw new Exception('La respuesta de la IA no es un JSON válido.');
            }

            // Flujo A: Ambigüedad / Missing Context
            if (isset($aiData['error']) && $aiData['error'] === 'missing_context') {
                $missingContext = $aiData['missing_context'] ?? [];
                $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString, null, $usageData, false, 'IA solicita más contexto', $schemaContextLog, $dialect);

                return $this->sendResponse([
                    'feedback' => [
                        'type' => 'missing_context',
                        'details' => $missingContext,
                        'thoughts' => $aiData['thoughts'] ?? ''
                    ],
                    'conversation_id' => $conversationId
                ], 'Se requiere más información para generar la consulta.');
            }

            // Flujo B: Éxito SQL
            if (isset($aiData['sql'])) {
                $sqlQuery = $aiData['sql'];

                // Validación de seguridad SQL
                $this->sqlValidationService->validate($sqlQuery);

                $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString, $sqlQuery, $usageData, true, null, $schemaContextLog, $dialect);

                return $this->sendResponse([
                    'sql' => $sqlQuery,
                    'thoughts' => $aiData['thoughts'] ?? null,
                    'usage' => $usageData,
                    'conversation_id' => $conversationId
                ], 'Traducción completada.');
            }

            throw new Exception('Formato de respuesta desconocido de la IA.');

        } catch (SQLValidationException $e) {
            $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString ?? '', null, $usageData, false, 'SQL Inseguro: ' . $e->getMessage(), $schemaContextLog, $dialect);
            return $this->sendError('La consulta generada no pasó los filtros de seguridad.', Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('TranslateController Error: ' . $e->getMessage());
            $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString ?? '', null, $usageData, false, $e->getMessage(), $schemaContextLog, $dialect);

            return $this->sendError(
                'Ocurrió un error al procesar la traducción.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                config('app.debug') ? ['details' => $e->getMessage()] : []
            );
        }
    }

    /**
     * Nuevo método exclusivo para generar datos de gráficos.
     */
public function generateChart(Request $request)
    {
        // 1. Validar
        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'conversation_id' => 'nullable|string|max:255',
            'tables' => 'required|array|min:1',
            'tables.*.id' => 'required|integer|exists:schema_tables,id',
            'tables.*.full_schema' => 'nullable|boolean',
            'tables.*.columns' => 'nullable|array',
            'tables.*.columns.*' => 'string',
        ]);

        $user = $request->user();
        $conversationId = $validated['conversation_id'] ?? (string) Str::uuid();

        // 2. Extraer IDs
        $tablesInput = collect($validated['tables']);
        $tableIds = $tablesInput->pluck('id')->all();
        $configMap = $tablesInput->keyBy('id');

        // Variables de auditoría
        $aiResponseString = '';
        $usageData = [];
        $sqlQuery = null;
        $schemaContextLog = null;
        $dialect = null;

        try {
            // 3. Cargar y Validar Seguridad
            $tablesToLoad = SchemaTable::with('schema')->whereIn('id', $tableIds)->get();
            if ($tablesToLoad->isEmpty()) {
                throw new Exception('No se encontraron tablas.', Response::HTTP_NOT_FOUND);
            }
            $schema = $tablesToLoad->first()->schema;

            if ((int)$user->id !== (int)$schema->user_id) {
                return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
            }

            $dialect = $schema->dialect;

            // 4. FILTRADO DE COLUMNAS
            $filteredTables = $tablesToLoad->map(function ($table) use ($configMap) {
                $tableClone = clone $table;
                $config = $configMap->get($table->id);

                if (!empty($config['full_schema']) && $config['full_schema'] === true) {
                    return $tableClone;
                }

                $requestedColumns = $config['columns'] ?? [];

                if (is_array($tableClone->column_metadata)) {
                    $metaCollection = collect($tableClone->column_metadata);
                    $filteredCollection = $metaCollection->filter(function ($meta) use ($requestedColumns) {
                        if (!empty($requestedColumns)) return in_array($meta['col'], $requestedColumns);
                        return isset($meta['is_default']) && $meta['is_default'] === true;
                    });

                    $tableClone->column_metadata = $filteredCollection->values()->all();
                }
                return $tableClone;
            });

            // 5. Llamar a la IA (Solo para obtener SQL y Config)
            $schemaTablesObjects = $filteredTables->all();
            $schemaContextLog = $this->togetherAIService->getSchemaContext($schemaTablesObjects);

            $serviceResponse = $this->togetherAIService->generateChartSql(
                $validated['question'],
                $dialect,
                $schemaTablesObjects,
                $schema->database_name_prefix,
                $user,
                $conversationId
            );

            $aiResponseString = $serviceResponse['chart_response'];
            $usageData = $serviceResponse['usage'];

            // 6. Tokens
            DB::transaction(function () use ($user, $usageData) {
                $user->increment('monthly_requests_count');
                if (isset($usageData['total_tokens'])) {
                    $user->increment('monthly_token_count', $usageData['total_tokens']);
                }
            });

            // 7. Procesar Respuesta
            $aiData = json_decode($aiResponseString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (preg_match('/\{.*\}/s', $aiResponseString, $matches)) {
                    $aiData = json_decode($matches[0], true);
                }
            }

            if (!$aiData) {
                throw new Exception('La IA no devolvió un JSON válido para gráficos.');
            }

            // 8. Manejo de errores lógicos de la IA
            if (isset($aiData['error']) && $aiData['error'] === 'not_chartable') {
                 $this->logToHistory($user, $conversationId, $validated['question'], $aiResponseString, null, $usageData, false, 'not_chartable', $schemaContextLog, $dialect);

                 return $this->sendResponse([
                    'feedback' => [
                        'type' => 'not_chartable',
                        'message' => $aiData['message'] ?? 'Consulta no apta para gráfico.'
                    ]
                 ], 'Consulta no apta para gráfico.');
            }

            $sqlQuery = $aiData['sql'] ?? null;
            $chartConfig = $aiData['chart_config'] ?? [];

            // 9. Validar SQL (Siempre validamos seguridad, aunque no ejecutemos)
            $this->sqlValidationService->validate($sqlQuery);

            // 10. Guardar Historial y Responder
            $this->logToHistory($user, $conversationId, $validated['question'], $aiResponseString, $sqlQuery, $usageData, true, null, $schemaContextLog, $dialect);

            // RESPUESTA LIMPIA: Solo SQL y Configuración Visual
            return $this->sendResponse([
                'sql' => $sqlQuery,
                'chart_config' => $chartConfig, // Contiene: type, xaxis_column, series_column, title
                'thoughts' => $aiData['thoughts'] ?? null,
                'usage' => $usageData,
                'conversation_id' => $conversationId
            ], 'Definición de gráfico generada.');

        } catch (Exception $e) {
            Log::error('Chart Error: ' . $e->getMessage());
            $this->logToHistory($user, $conversationId, $validated['question'], $aiResponseString ?? '', null, $usageData, false, $e->getMessage(), $schemaContextLog ?? null, $schema->dialect ?? null);
            return $this->sendError('Error generando definición de gráfico: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function logToHistory(\App\Models\User $user, ?string $conversationId, string $question, string $rawResponse, ?string $sqlQuery, array $usageData, bool $wasSuccessful, ?string $errorMessage, ?string $schemaContext, ?string $dialect): void
    {
        try {
            $user->promptHistories()->create([
                'conversation_id' => $conversationId,
                'question' => $question,
                'schema_context' => $schemaContext,
                'dialect' => $dialect,
                'raw_response' => $rawResponse,
                'generated_sql' => $sqlQuery,
                'was_successful' => $wasSuccessful,
                'error_message' => $errorMessage,
                'prompt_tokens' => $usageData['prompt_tokens'] ?? 0,
                'completion_tokens' => $usageData['completion_tokens'] ?? 0,
                'total_tokens' => $usageData['total_tokens'] ?? 0,
            ]);
        } catch (Exception $e) {
            Log::error('Fallo al guardar PromptHistory: ' . $e->getMessage());
        }
    }
}
