<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Schema;
use App\Models\SchemaTable;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SchemaTableController extends Controller
{
    use ApiResponser;

    /**
     * Crea una nueva definición de tabla y la asocia a un esquema existente.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'schema_id' => 'required|integer|exists:schemas,id',
            'table_name' => 'required|string|max:255',
            'definition' => 'required|string',
            'column_metadata' => 'nullable|array', // <-- Validación
        ]);

        // --- Comprobación de Autorización (Paso 1: ¿Existe el esquema?) ---
        $schema = Schema::find($validated['schema_id']);

        // --- Comprobación de Autorización (Paso 2: ¿Pertenece al usuario?) ---
        if ($request->user()->id !== $schema->user_id) {
            return $this->sendError('Acceso no autorizado a este esquema.', Response::HTTP_FORBIDDEN);
        }

        $schemaTable = $schema->schemaTables()->create($validated);

        return $this->sendResponse($schemaTable, 'Tabla de esquema creada exitosamente.', Response::HTTP_CREATED);
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
