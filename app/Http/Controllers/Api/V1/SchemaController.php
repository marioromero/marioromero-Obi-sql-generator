<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Schema;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SchemaController extends Controller
{
    use ApiResponser;

    /**
     * Muestra todos los esquemas (y sus tablas) del usuario autenticado.
     */
    public function index(Request $request)
    {
        $schemas = $request->user()->schemas()->with('schemaTables')->latest()->get();
        return $this->sendResponse($schemas, 'Esquemas recuperados exitosamente.');
    }

    /**
     * Crea un nuevo esquema (carpeta) para el usuario autenticado.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255', // El agente envía el connection_key aquí
            'dialect' => 'required|string|max:50',
            'database_name_prefix' => 'nullable|string|max:100',
        ]);

        // LÓGICA UPSERT (LA SOLUCIÓN AL PROBLEMA DE DUPLICADOS)
        // Buscamos un esquema que coincida en USUARIO + NOMBRE
        $schema = \App\Models\Schema::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'name' => $validated['name']
            ],
            [
                // Solo actualizamos estos campos si ya existe, o los usamos para crear
                'dialect' => $validated['dialect'],
                'database_name_prefix' => $validated['database_name_prefix'] ?? null
            ]
        );

        return $this->sendResponse($schema, 'Esquema sincronizado correctamente.', Response::HTTP_OK);
    }

    /**
     * Muestra un esquema específico (y sus tablas).
     */
    public function show(Request $request, Schema $schema)
    {
        // --- Comprobación de Autorización ---
        if ($request->user()->id !== $schema->user_id) {
            return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
        }

        return $this->sendResponse($schema->load('schemaTables'), 'Esquema recuperado exitosamente.');
    }

    /**
     * Actualiza el nombre o dialecto de un esquema.
     */
    public function update(Request $request, Schema $schema)
    {
        // --- Comprobación de Autorización ---
        if ($request->user()->id !== $schema->user_id) {
            return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'dialect' => 'sometimes|required|string|max:50',
            'database_name_prefix' => 'sometimes|nullable|string|max:100',
        ]);

        $schema->update($validated);

        return $this->sendResponse($schema->load('schemaTables'), 'Esquema actualizado exitosamente.');
    }

    /**
     * Elimina un esquema y todas sus tablas asociadas (gracias a cascadeOnDelete).
     */
    public function destroy(Request $request, Schema $schema)
    {
        // --- Comprobación de Autorización ---
        if ($request->user()->id !== $schema->user_id) {
            return $this->sendError('Acceso no autorizado.', Response::HTTP_FORBIDDEN);
        }

        $schema->delete();

        return $this->sendResponse(null, 'Esquema eliminado exitosamente.');
    }
}
