<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SQLValidationException;
use App\Http\Controllers\Controller;
use App\Models\SchemaTable;
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
        protected TogetherAIService $togetherAIService,
        protected SQLValidationService $sqlValidationService
    ) {
    }

    public function translate(Request $request)
    {
        // 1. Validar la petición
        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'conversation_id' => 'nullable|string|max:255',
            'schema_table_ids' => 'required|array|min:1',
            'schema_table_ids.*' => 'integer|exists:schema_tables,id',
            // El schema_config es opcional, pero si viene, define qué columnas ve la IA
            'schema_config' => 'nullable|array',
            'schema_config.*.table_id' => 'required_with:schema_config|integer',
            'schema_config.*.use_full_schema' => 'required_with:schema_config|boolean',
            'schema_config.*.include_columns' => 'nullable|array',
            'schema_config.*.include_columns.*' => 'string',
        ]);

        $user = $request->user();
        $userQuestion = $validated['question'];
        $conversationId = $validated['conversation_id'] ?? (string) Str::uuid();
        $tableIds = $validated['schema_table_ids'];

        // Variables para log y auditoría
        $sqlQuery = null;
        $aiResponseString = '';
        $usageData = [];
        $schemaContextLog = null; // Para guardar qué vio exactamente la IA
        $dialect = null;

        try {
            // 2. Cargar Tablas
            $tablesToLoad = SchemaTable::with('schema')
                ->whereIn('id', $tableIds)
                ->get();

            if ($tablesToLoad->isEmpty()) {
                throw new Exception('No se encontraron las tablas solicitadas.', Response::HTTP_NOT_FOUND);
            }

            // Validar propiedad del esquema (Security Check)
            $schema = $tablesToLoad->first()->schema;
            if ((int)$user->id !== (int)$schema->user_id) {
                // Log::warning("Intento de acceso cruzado: User {$user->id} intentó acceder a Schema {$schema->id}");
                return $this->sendError('Acceso no autorizado al esquema de datos.', Response::HTTP_FORBIDDEN);
            }

            $dialect = $schema->dialect;
            $dbPrefix = $schema->database_name_prefix;

            // 3. OPTIMIZACIÓN Y FILTRADO DE COLUMNAS
            // Procesamos la metadata para enviar a la IA SOLO lo que el front permite
            $schemaConfig = $request->input('schema_config', []);
            $configMap = collect($schemaConfig)->keyBy('table_id');

            $filteredTables = $tablesToLoad->map(function ($table) use ($configMap) {
                // Clonamos para no afectar el modelo en memoria si se usara después
                $tableClone = clone $table;

                // Si no hay config específica para esta tabla, asumimos FULL schema (o podrías asumir vacío según tu lógica de negocio)
                $config = $configMap->get($table->id);

                if (!$config) {
                    // Si no mandan config, ¿mandamos todo? Asumamos que sí por defecto.
                    return $tableClone;
                }

                // Si use_full_schema es true, devolvemos todo intacto
                if (!empty($config['use_full_schema']) && $config['use_full_schema'] === true) {
                    return $tableClone;
                }

                // Filtrar columnas
                $requestedColumns = $config['include_columns'] ?? [];

                if (is_array($tableClone->column_metadata)) {
                    $tableClone->column_metadata = collect($tableClone->column_metadata)
                        ->filter(function ($meta) use ($requestedColumns) {
                            return in_array($meta['col'], $requestedColumns);
                        })
                        ->values()
                        ->all();
                }

                return $tableClone;
            });

            // 4. Preparar Contexto y Llamar a la IA
            $schemaTablesObjects = $filteredTables->all();

            // Generamos el contexto aquí para guardarlo en el historial exactamente como se generó
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

            // 5. Gestión de Tokens (Transaccional)
            DB::transaction(function () use ($user, $usageData) {
                $user->increment('monthly_requests_count');
                if (isset($usageData['total_tokens'])) {
                    $user->increment('monthly_token_count', $usageData['total_tokens']);
                }
            });

            // 6. Procesar Respuesta
            $aiData = json_decode($aiResponseString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Intento de recuperación si la IA mandó texto antes del JSON
                if (preg_match('/\{.*\}/s', $aiResponseString, $matches)) {
                    $aiData = json_decode($matches[0], true);
                }
            }

            if (!$aiData) {
                throw new Exception('La respuesta de la IA no es un JSON válido.');
            }

            // A. Flujo de Ambigüedad/Error de IA
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

            // B. Flujo Éxito SQL
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
            Log::error('TranslateController Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString ?? '', null, $usageData, false, $e->getMessage(), $schemaContextLog, $dialect);

            return $this->sendError(
                'Ocurrió un error al procesar la traducción.',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                config('app.debug') ? ['details' => $e->getMessage()] : []
            );
        }
    }

    /**
     * Helper para guardar historial.
     */
    private function logToHistory(User $user, ?string $conversationId, string $question, string $rawResponse, ?string $sqlQuery, array $usageData, bool $wasSuccessful, ?string $errorMessage, ?string $schemaContext, ?string $dialect): void
    {
        try {
            $user->promptHistories()->create([
                'conversation_id' => $conversationId,
                'question' => $question,
                'schema_context' => $schemaContext, // Ahora guardamos el contexto exacto usado
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
