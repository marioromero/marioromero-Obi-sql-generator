<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SQLValidationException;
use App\Http\Controllers\Controller;
use App\Models\Schema;
use App\Models\SchemaTable;
use App\Models\User;
use App\Services\TogetherAIService;
use App\Services\SQLValidationService;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        // 1. Validar la petición (Nuevo DTO)
        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'conversation_id' => 'nullable|string|max:255',
            'schema_table_ids' => 'required|array|min:1', // Ahora las tablas son siempre requeridas
            'schema_table_ids.*' => 'integer|exists:schema_tables,id',
            'schema_config' => 'nullable|array', // El DTO de optimización
            'schema_config.*.table_id' => 'required|integer|exists:schema_tables,id',
            'schema_config.*.use_full_schema' => 'required|boolean',
            'schema_config.*.include_columns' => 'sometimes|array',
            'schema_config.*.include_columns.*' => 'string',
        ]);

        $user = $request->user();
        $userQuestion = $validated['question'];
        $conversationId = $validated['conversation_id'] ?? (string) Str::uuid(); // Siempre generamos/usamos uno
        $tableIds = $validated['schema_table_ids'];

        // --- Variables de auditoría ---
        $sqlQuery = null;
        $aiResponseString = '';
        $usageData = [];
        $wasSuccessful = false;
        $errorMessage = null;

        try {
            // 2. Cargar Tablas Base y Autorizar
            $tablesToLoad = SchemaTable::with('schema')->whereIn('id', $tableIds)->get();
            $schema = $tablesToLoad->first()->schema; // Asumir que todas son del mismo schema

            // Autorización
            if ($user->id !== $schema->user_id) {
                throw new Exception('No autorizado para usar este esquema.', Response::HTTP_FORBIDDEN);
            }
            if ($tablesToLoad->isEmpty()) {
                throw new Exception('No se especificaron tablas.', Response::HTTP_BAD_REQUEST);
            }

            // 3. APLICAR FILTRADO DE COLUMNAS (OPTIMIZACIÓN INTELIGENTE)
            $schemaConfig = $request->input('schema_config', []);
            $configMap = collect($schemaConfig)->keyBy('table_id');

            $filteredTables = $tablesToLoad->map(function ($table) use ($configMap) {
                $config = $configMap->get($table->id);

                // A. Si no hay config para esta tabla, o 'use_full_schema' es true, mandamos todo.
                if (!$config || ($config['use_full_schema'] ?? true)) {
                    return $table;
                }

                // B. Si 'use_full_schema' es false, filtramos.
                $requestedColumns = $config['include_columns'] ?? [];

                // --- FUSIÓN INTELIGENTE ---
                $filteredMetadata = collect($table->column_metadata)
                    ->filter(function ($meta) use ($requestedColumns) {
                        $colName = $meta['col'];
                        $isDefault = $meta['is_default'] ?? false;
                        return in_array($colName, $requestedColumns) || $isDefault;
                    })
                    ->values()
                    ->all();

                $table->column_metadata = $filteredMetadata;
                return $table;
            });

            // 4. Preparar datos para la IA
            $dialect = $schema->dialect;
            $dbPrefix = $schema->database_name_prefix;
            $schemaTablesObjects = $filteredTables->all(); // Usamos las tablas filtradas

            // 5. Llamar al "Traductor"
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

            Log::debug('Respuesta CRUDA de la IA:', ['response' => $aiResponseString]);
            Log::info('Uso de tokens:', $usageData);

            // 6. Cobro de Tokens Inmediato
            try {
                DB::transaction(function () use ($user, $usageData) {
                    $user->increment('monthly_requests_count');
                    $user->increment('monthly_token_count', $usageData['total_tokens'] ?? 0);
                });
            } catch (Exception $e) {
                Log::critical('¡FALLO EL INCREMENTO DE CONTADORES!', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                throw new Exception('Error interno al procesar la solicitud.');
            }

            // 7. Decodificar la respuesta JSON de la IA
            $aiData = json_decode($aiResponseString, true);

            // 8. Flujo A: Ambigüedad (ÉXITO de Feedback)
            if ($aiData && isset($aiData['error'])) {
                $errorMessage = $aiData['error'];
                $missingContext = $aiData['missing_context'] ?? [];
                Log::warning('IA detectó ambigüedad:', ['error' => $errorMessage, 'missing_context' => $missingContext]);

                $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString, null, $usageData, false, $errorMessage);

                return $this->sendResponse(
                    data: [
                        'feedback' => [
                            'error' => $errorMessage,
                            'missing_context' => $missingContext
                        ],
                        'conversation_id' => $conversationId,
                        'usage' => $usageData
                    ],
                    message: 'La IA necesita más información.'
                );
            }

            // 9. Flujo B: Éxito de SQL
            if ($aiData && isset($aiData['sql'])) {
                $sqlQuery = $aiData['sql'];

                // 10. Pasar el SQL *limpio* al "Guardián de Seguridad"
                $this->sqlValidationService->validate($sqlQuery);

                // 11. --- ÉXITO ---
                $wasSuccessful = true;
                $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString, $sqlQuery, $usageData, $wasSuccessful, null);

                return $this->sendResponse(
                    data: [
                        'sql' => $sqlQuery,
                        'usage' => $usageData,
                        'conversation_id' => $conversationId
                    ],
                    message: 'Traducción generada y validada exitosamente.'
                );
            }

            // 12. Flujo C: Error Inesperado de Formato
            throw new Exception('La IA devolvió un formato de respuesta inesperado.');

        } catch (SQLValidationException $e) {
            $errorMessage = 'Validación de SQL fallida: ' . $e->getMessage();
            Log::warning($errorMessage, ['query' => $sqlQuery ?? $aiResponseString]);
            $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString, null, $usageData, false, $errorMessage);
            return $this->sendError($errorMessage, Response::HTTP_BAD_REQUEST);

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $httpCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

            $errorData = null;
            if ($httpCode === Response::HTTP_BAD_REQUEST) {
                $aiData = json_decode($aiResponseString, true);
                $errorData = $aiData['missing_context'] ?? null;
            }

            Log::error('Error en TranslateController: ' . $errorMessage);
            $this->logToHistory($user, $conversationId, $userQuestion, $aiResponseString, null, $usageData, false, $errorMessage);

            return $this->sendError(
                'El servicio de traducción falló: ' . $errorMessage,
                $httpCode,
                $errorData
            );
        }
    }

    /**
     * Método auxiliar para guardar el registro en la tabla prompt_history.
     */
    private function logToHistory(User $user, ?string $conversationId, string $question, string $rawResponse, ?string $sqlQuery, array $usageData, bool $wasSuccessful, ?string $errorMessage): void
    {
        if (empty($question) && empty($rawResponse)) {
            return;
        }

        try {
            $user->promptHistories()->create([
                'conversation_id' => $conversationId,
                'question' => $question,
                'raw_response' => $rawResponse,
                'generated_sql' => $sqlQuery,
                'was_successful' => $wasSuccessful,
                'error_message' => $errorMessage,
                'prompt_tokens' => $usageData['prompt_tokens'] ?? 0,
                'completion_tokens' => $usageData['completion_tokens'] ?? 0,
                'total_tokens' => $usageData['total_tokens'] ?? 0,
            ]);
        } catch (Exception $e) {
            Log::error('¡Fallo al guardar en PromptHistory!', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
