<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponser
{
    /**
     * Construye una respuesta de ÉXITO estandarizada.
     * El status del JSON es SIEMPRE TRUE.
     *
     * @param mixed $data Los datos a devolver.
     * @param string $message Un mensaje descriptivo.
     * @param int $httpCode El código de estado HTTP (por defecto 200 OK).
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendResponse($data, $message, $httpCode = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'status'  => true, // <-- CORREGIDO: Siempre 'true' en éxito
            'message' => $message,
            'data'    => $data,
        ], $httpCode); // <-- El código HTTP real (200, 201, etc.)
    }

    /**
     * Construye una respuesta de ERROR estandarizada.
     * El status del JSON es SIEMPRE FALSE.
     *
     * @param string $message Un mensaje de error.
     * @param int $httpCode El código de estado HTTP (por defecto 400 Bad Request).
     * @param mixed|null $data Datos adicionales del error (opcional).
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendError($message, $httpCode = Response::HTTP_BAD_REQUEST, $data = null): JsonResponse
    {
        return response()->json([
            'status'  => false, // <-- CORREGIDO: Siempre 'false' en error
            'message' => $message,
            'data'    => $data,
        ], $httpCode); // <-- El código HTTP real (400, 404, 422, etc.)
    }
}
