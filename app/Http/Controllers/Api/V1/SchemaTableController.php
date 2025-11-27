<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Schema;
use App\Models\SchemaTable;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SchemaTableController extends Controller
{
    use ApiResponser;

    /**
     * Crea una nueva definición de tabla y la asocia a un esquema existente.
     */
public function store(Request $request)
    {
        // Log Inicial
        Log::info("----------------------------------------------------");
        Log::info("🔄 [SchemaTable Sync] Iniciando sincronización de tabla.");
        Log::info("📥 Datos recibidos:", $request->only(['schema_id', 'table_name']));

        $validated = $request->validate([
            'schema_id' => 'required|integer|exists:schemas,id',
            'table_name' => 'required|string|max:255',
            'definition' => 'required|string',
            'column_metadata' => 'nullable|array',
        ]);

        // 1. Comprobación de Autorización
        $schema = Schema::find($validated['schema_id']);

        if (!$schema || $request->user()->id !== $schema->user_id) {
            Log::warning("⛔ Acceso denegado. Usuario ID: {$request->user()->id} vs Schema Owner: " . ($schema->user_id ?? 'N/A'));
            return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
        }

        // 2. Depuración de Existencia (La clave del problema)
        // Verificamos manualmente antes de hacer el updateOrCreate para saber qué está pasando
        $existingTable = SchemaTable::where('schema_id', $validated['schema_id'])
                                    ->where('table_name', $validated['table_name'])
                                    ->first();

        if ($existingTable) {
            Log::info("✅ ENCONTRADO: La tabla '{$validated['table_name']}' ya existe en el Schema ID {$validated['schema_id']} (Table ID: {$existingTable->id}). Se actualizará.");
        } else {
            Log::warning("⚠️ NO ENCONTRADO: No existe la tabla '{$validated['table_name']}' en el Schema ID {$validated['schema_id']}. Se creará una NUEVA.");

            // DEBUG EXTRA: ¿Existe esa tabla en OTROS esquemas del mismo usuario?
            // Esto nos dirá si se está duplicando el esquema padre.
            $dupesInOtherSchemas = SchemaTable::whereHas('schema', function($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })->where('table_name', $validated['table_name'])->get();

            if ($dupesInOtherSchemas->count() > 0) {
                Log::error("🚨 ALERTA DE DUPLICADO DE ESQUEMA: La tabla '{$validated['table_name']}' existe en estos otros Schema IDs: " . $dupesInOtherSchemas->pluck('schema_id')->implode(', '));
                Log::error("👉 CONCLUSIÓN: El problema está en SchemaController. Se está creando un Schema ID nuevo cada vez.");
            }
        }

        // 3. Lógica Upsert
        $schemaTable = SchemaTable::updateOrCreate(
            [
                'schema_id' => $validated['schema_id'],
                'table_name' => $validated['table_name']
            ],
            [
                'definition' => $validated['definition'],
                'column_metadata' => $validated['column_metadata']
            ]
        );

        Log::info("🏁 Resultado Final: Tabla ID {$schemaTable->id} " . ($schemaTable->wasRecentlyCreated ? 'CREADA' : 'ACTUALIZADA'));

        return $this->sendResponse($schemaTable, 'Tabla sincronizada.', Response::HTTP_OK);
    }

    /**
     * Muestra una definición de tabla específica.
     */
    public function show(Request $request, SchemaTable $schemaTable)
    {
        // --- Comprobación de Autorización ---
        // (Verifica que el usuario sea el dueño del "esquema padre")
        if ($request->user()->id !== $schemaTable->schema->user_id) {
            return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
        }

        return $this->sendResponse($schemaTable, 'Tabla de esquema recuperada exitosamente.');
    }

    /**
     * Actualiza una definición de tabla.
     */
    public function update(Request $request, SchemaTable $schemaTable)
    {
        // --- Comprobación de Autorización ---
        if ($request->user()->id !== $schemaTable->schema->user_id) {
            return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'table_name' => 'sometimes|required|string|max:255',
            'definition' => 'sometimes|required|string',
            'column_metadata' => 'nullable|array',
        ]);

        $schemaTable->update($validated);

        return $this->sendResponse($schemaTable, 'Tabla de esquema actualizada exitosamente.');
    }

    /**
     * Elimina una definición de tabla.
     */
    public function destroy(Request $request, SchemaTable $schemaTable)
    {
        // --- Comprobación de Autorización ---
        if ($request->user()->id !== $schemaTable->schema->user_id) {
            return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
        }

        $schemaTable->delete();

        return $this->sendResponse(null, 'Tabla de esquema eliminada exitosamente.');
    }
}
