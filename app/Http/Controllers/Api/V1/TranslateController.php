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
use Exception;

class TranslateController extends Controller
{
    use ApiResponser;

    public function __construct(
        protected TogetherAIService $togetherAIService,
        protected SQLValidationService $sqlValidationService
    ) {
    }

    /**
     * El endpoint principal de Text-to-SQL.
     */
    public function translate(Request $request)
    {
        // 1. Validar la petición
        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'schema_id' => 'sometimes|integer|exists:schemas,id',
            'schema_table_ids' => 'required_without:schema_id|array|min:1',
            'schema_table_ids.*' => 'integer|exists:schema_tables,id',
        ]);

        $user = $request->user();
        $userQuestion = $validated['question'];

        // --- Variables de auditoría (definidas en el scope principal) ---
        $schemaDefinitions = [];
        $dialect = '';
        $sqlQuery = null;
        $aiResponseString = '';
        $usageData = [];
        $wasSuccessful = false;
        $errorMessage = null;

        try {
            // 2. Lógica de Carga Dinámica de Tablas y Autorización
            $tablesToLoad = collect();
            $schema = null;

            if (isset($validated['schema_table_ids'])) {
                $tableIds = $validated['schema_table_ids'];
                $tablesToLoad = SchemaTable::with('schema')->whereIn('id', $tableIds)->get();

                foreach ($tablesToLoad as $table) {
                    if ($table->schema->user_id !== $user->id) {
                        throw new Exception('Acceso no autorizado a una o más tablas del esquema.', Response::HTTP_FORBIDDEN);
                    }
                }
                $schema = $tablesToLoad->first()->schema;
            } else {
                $schema = Schema::with('schemaTables')->find($validated['schema_id']);
                if ($user->id !== $schema->user_id) {
                    throw new Exception('No autorizado para usar este esquema.', Response::HTTP_FORBIDDEN);
                }
                $tablesToLoad = $schema->schemaTables;
            }

            if ($tablesToLoad->isEmpty()) {
                throw new Exception('No se especificaron tablas o el esquema está vacío.', Response::HTTP_BAD_REQUEST);
            }

            // 4. Preparar datos para la IA
            $dialect = $schema->dialect;
            $schemaDefinitions = $tablesToLoad->pluck('definition')->all();

            // 5. Llamar al "Traductor" (El evento facturable)
            $serviceResponse = $this->togetherAIService->generateSql($userQuestion, $dialect, $schemaDefinitions);
            $aiResponseString = $serviceResponse['sql_or_error'];
            $usageData = $serviceResponse['usage'];

            Log::debug('Respuesta CRUDA de la IA:', ['response' => $aiResponseString]);
            Log::info('Uso de tokens:', $usageData);

            // --- ¡NUEVO! PASO 6: COBRO DE TOKENS INMEDIATO ---
            // Le cobramos al usuario por la llamada a la IA, sin importar si
            // la respuesta es válida o no.
            try {
                DB::transaction(function () use ($user, $usageData) {
                    $user->increment('monthly_requests_count');
                    $user->increment('monthly_token_count', $usageData['total_tokens'] ?? 0);
                });
            } catch (Exception $e) {
                // Si el incremento falla (ej. BD desconectada), es un error crítico.
                // No continuamos y lo registramos, pero el usuario no verá este error.
                Log::critical('¡FALLO EL INCREMENTO DE CONTADORES!', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                throw new Exception('Error interno al procesar la solicitud.');
            }
            // --- FIN DEL COBRO ---

            // 7. Decodificar la respuesta JSON de la IA
            $aiData = json_decode($aiResponseString, true);

            // 8. Manejar Ambigüedad
            if ($aiData && isset($aiData['error'])) {
                // El usuario PAGA por este error (ya cobramos)
                throw new Exception($aiData['error'], Response::HTTP_BAD_REQUEST);
            }

            // 9. Extraer el SQL
            if (!$aiData || !isset($aiData['sql'])) {
                // El usuario PAGA por este error (ya cobramos)
                throw new Exception('La IA devolvió un formato de respuesta inesperado.');
            }
            $sqlQuery = $aiData['sql'];

            // 10. Pasar el SQL *limpio* al "Guardián de Seguridad"
            // El usuario PAGA por este error (ya cobramos)
            $this->sqlValidationService->validate($sqlQuery);

            // 11. --- ÉXITO ---
            $wasSuccessful = true;
            $this->logToHistory($user, $userQuestion, $schemaDefinitions, $dialect, $aiResponseString, $sqlQuery, $usageData, $wasSuccessful, null);

            return $this->sendResponse(
                data: [
                    'sql' => $sqlQuery,
                    'usage' => $usageData
                ],
                message: 'Traducción generada y validada exitosamente.'
            );

        } catch (SQLValidationException $e) {
            $errorMessage = 'Validación de SQL fallida: ' . $e->getMessage();
            Log::warning($errorMessage, ['query' => $sqlQuery ?? $aiResponseString]);
            // Registramos el fallo (el usuario ya pagó)
            $this->logToHistory($user, $userQuestion, $schemaDefinitions, $dialect, $aiResponseString, null, $usageData, false, $errorMessage);
            return $this->sendError(
                'La consulta generada no es segura y ha sido rechazada: ' . $e->getMessage(),
                Response::HTTP_BAD_REQUEST
            );

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $httpCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

            // Si el error ocurrió ANTES del cobro (ej. "Acceso no autorizado"), $usageData estará vacío
            // Si ocurrió DESPUÉS del cobro (ej. "Ambigüedad"), $usageData estará lleno
            Log::error('Error en TranslateController: ' . $errorMessage);
            $this->logToHistory($user, $userQuestion, $schemaDefinitions, $dialect, $aiResponseString, null, $usageData, false, $errorMessage);

            return $this->sendError(
                'El servicio de traducción falló: ' . $errorMessage,
                $httpCode
            );
        }
    }

    /**
     * Método auxiliar para guardar el registro en la tabla prompt_history.
     * (La firma del método cambió para aceptar el User object)
     */
    private function logToHistory(User $user, string $question, array $schemaDefinitions, string $dialect, string $rawResponse, ?string $sqlQuery, array $usageData, bool $wasSuccessful, ?string $errorMessage): void
    {
        // Si la pregunta está vacía (ej. falló la validación de autorización), no loguear
        if (empty($question) && empty($rawResponse)) {
            return;
        }

        try {
            $user->promptHistories()->create([
                'question' => $question,
                'schema_context' => implode("\n\n", $schemaDefinitions),
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
            Log::error('¡Fallo al guardar en PromptHistory!', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
