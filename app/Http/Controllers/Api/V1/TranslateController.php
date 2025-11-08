<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SQLValidationException;
use App\Http\Controllers\Controller;
use App\Models\Schema;
use App\Services\TogetherAIService;
use App\Services\SQLValidationService;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
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
        // 1. Validar la petición del usuario
        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'schema_id' => 'required|integer|exists:schemas,id',
        ]);

        $user = $request->user();
        $schemaId = $validated['schema_id'];
        $userQuestion = $validated['question'];

        try {
            // 2. Cargar el Contexto (Esquema y Tablas)
            $schema = Schema::with('schemaTables')->find($schemaId);

            // 3. Comprobación de Autorización
            if ($user->id !== $schema->user_id) {
                return $this->sendError('No autorizado para usar este esquema.', Response::HTTP_FORBIDDEN);
            }

            if ($schema->schemaTables->isEmpty()) {
                return $this->sendError('Este esquema no tiene tablas definidas.', Response::HTTP_BAD_REQUEST);
            }

            // 4. Preparar datos para la IA
            $dialect = $schema->dialect;
            $schemaDefinitions = $schema->schemaTables->pluck('definition')->all();

            // 5. Llamar al "Traductor" (Ahora devuelve un array)
            $serviceResponse = $this->togetherAIService->generateSql($userQuestion, $dialect, $schemaDefinitions);

            $aiResponseString = $serviceResponse['sql_or_error'];
            $usageData = $serviceResponse['usage']; // <-- Captura el uso

            Log::debug('Respuesta CRUDA de la IA:', ['response' => $aiResponseString]);
            Log::info('Uso de tokens:', $usageData); // <-- Loguea el uso

            // 6. Decodificar la respuesta JSON de la IA
            $aiData = json_decode($aiResponseString, true);

            // 7. Manejar Ambigüedad (si la IA nos envió un error)
            if ($aiData && isset($aiData['error']) && $aiData['error'] === true) {
                Log::warning('IA detectó ambigüedad:', $aiData);
                return $this->sendError($aiData['message'], Response::HTTP_BAD_REQUEST);
            }

            // 8. Extraer el SQL (si no hubo error)
            if (!$aiData || !isset($aiData['sql'])) {
                Log::error('Respuesta inesperada de la IA (no es un JSON de error ni de sql):', ['response' => $aiResponseString]);
                throw new Exception('La IA devolvió un formato de respuesta inesperado.');
            }

            // ¡Este es el string de SQL limpio!
            $sqlQuery = $aiData['sql'];

            // 9. Pasar el SQL *limpio* al "Guardián de Seguridad"
            $this->sqlValidationService->validate($sqlQuery);

            // 10. Devolver la respuesta de SQL (¡CON DATOS DE USO!)
            return $this->sendResponse(
                data: [
                    'sql' => $sqlQuery,
                    'usage' => $usageData // <-- Devuelve el uso
                ],
                message: 'Traducción generada y validada exitosamente.'
            );

        } catch (SQLValidationException $e) {
            // Captura el rechazo del Guardián (ej. DROP TABLE)
            Log::warning('Validación de SQL fallida: ' . $e->getMessage(), ['query' => $sqlQuery ?? $aiResponseString]);
            return $this->sendError(
                'La consulta generada no es segura y ha sido rechazada: ' . $e->getMessage(),
                Response::HTTP_BAD_REQUEST // 400
            );
        } catch (Exception $e) {
            // Captura errores de la API (si es la real) u otros.
            Log::error('Error en TranslateController: ' . $e->getMessage());
            return $this->sendError(
                'El servicio de traducción falló: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
