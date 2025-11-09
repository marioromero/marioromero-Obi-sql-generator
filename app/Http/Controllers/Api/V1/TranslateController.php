<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SQLValidationException;
use App\Http\Controllers\Controller;
use App\Models\Schema;
use App\Models\SchemaTable;
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
        // 1. Validar la petición del usuario (Reglas modificadas)
        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            // El schema_id ahora es opcional, pero lo usaremos si no se envían IDs de tabla
            'schema_id' => 'sometimes|integer|exists:schemas,id',

            // ¡NUEVO! Aceptamos un array de IDs de tablas
            'schema_table_ids' => 'required_without:schema_id|array|min:1',
            'schema_table_ids.*' => 'integer|exists:schema_tables,id',
        ]);

        $user = $request->user();
        $userQuestion = $validated['question'];

        try {
            $tablesToLoad = collect();
            $schema = null;

            // --- LÓGICA DE CARGA DINÁMICA ---

            if (isset($validated['schema_table_ids'])) {
                // Escenario 1: El usuario especificó qué tablas usar
                $tableIds = $validated['schema_table_ids'];

                // Cargamos las tablas y su 'schema' padre (para el dialecto y permisos)
                $tablesToLoad = SchemaTable::with('schema')->whereIn('id', $tableIds)->get();

                // Autorización: Verificar que todas las tablas pedidas pertenezcan al usuario
                foreach ($tablesToLoad as $table) {
                    if ($table->schema->user_id !== $user->id) {
                        return $this->sendError('Acceso no autorizado a una o más tablas del esquema.', Response::HTTP_FORBIDDEN);
                    }
                }

                // Obtenemos el dialecto de la primera tabla (asumimos que todas son del mismo schema)
                $schema = $tablesToLoad->first()->schema;

            } else {
                // Escenario 2: El usuario envió un schema_id (comportamiento antiguo)
                $schema = Schema::with('schemaTables')->find($validated['schema_id']);

                // Autorización
                if ($user->id !== $schema->user_id) {
                    return $this->sendError('No autorizado para usar este esquema.', Response::HTTP_FORBIDDEN);
                }

                $tablesToLoad = $schema->schemaTables;
            }

            if ($tablesToLoad->isEmpty()) {
                return $this->sendError('No se especificaron tablas o el esquema está vacío.', Response::HTTP_BAD_REQUEST);
            }
            // --- FIN LÓGICA DE CARGA ---


            // 4. Preparar datos para la IA
            $dialect = $schema->dialect;
            // Usamos las tablas filtradas, no todas
            $schemaDefinitions = $tablesToLoad->pluck('definition')->all();

            // 5. Llamar al "Traductor"
            $serviceResponse = $this->togetherAIService->generateSql($userQuestion, $dialect, $schemaDefinitions);

            // ... (el resto del método 'translate' (a partir del paso 5) es idéntico al del Paso 23)
            // ... (captura de 'usageData', parseo de JSON, validación del Guardián, etc.)

            $aiResponseString = $serviceResponse['sql_or_error'];
            $usageData = $serviceResponse['usage'];

            Log::debug('Respuesta CRUDA de la IA:', ['response' => $aiResponseString]);
            Log::info('Uso de tokens:', $usageData);

            $aiData = json_decode($aiResponseString, true);

            if ($aiData && isset($aiData['error']) && $aiData['error'] === true) {
                Log::warning('IA detectó ambigüedad:', $aiData);
                return $this->sendError($aiData['message'], Response::HTTP_BAD_REQUEST);
            }

            if (!$aiData || !isset($aiData['sql'])) {
                Log::error('Respuesta inesperada de la IA:', ['response' => $aiResponseString]);
                throw new Exception('La IA devolvió un formato de respuesta inesperado.');
            }

            $sqlQuery = $aiData['sql'];

            $this->sqlValidationService->validate($sqlQuery);

            return $this->sendResponse(
                data: [
                    'sql' => $sqlQuery,
                    'usage' => $usageData
                ],
                message: 'Traducción generada y validada exitosamente.'
            );

        } catch (SQLValidationException $e) {
            Log::warning('Validación de SQL fallida: ' . $e->getMessage(), ['query' => $sqlQuery ?? $aiResponseString]);
            return $this->sendError(
                'La consulta generada no es segura y ha sido rechazada: ' . $e->getMessage(),
                Response::HTTP_BAD_REQUEST
            );
        } catch (Exception $e) {
            Log::error('Error en TranslateController: ' . $e->getMessage());
            return $this->sendError(
                'El servicio de traducción falló: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
