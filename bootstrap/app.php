<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
->withExceptions(function (Exceptions $exceptions) {

    // 1. MANEJADOR PARA "RUTA NO ENCONTRADA" (404)
    $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {

        // ¡NUEVO! Logueamos la ruta que falló.
        Log::warning('404 - Ruta no encontrada: ' . $request->path());

        return response()->json([
            'status'  => 404,
            'message' => 'Ruta no encontrada (El endpoint no existe).',
            'data'    => null
        ], 404);
    });

    // 2. MANEJADOR PARA ERRORES DE VALIDACIÓN (422)
    $exceptions->renderable(function (ValidationException $e, Request $request) {

        // ¡NUEVO! Logueamos los errores de validación como 'info'.
        Log::info('422 - Error de validación: ', $e->errors());

        return response()->json([
            'status'  => 422,
            'message' => 'Los datos proporcionados no son válidos.',
            'data'    => $e->errors()
        ], 422);
    });

    // 3. (OPCIONAL) MANEJADOR GENÉRICO PARA OTROS ERRORES (500)
    $exceptions->renderable(function (\Throwable $e, Request $request) {

        // ¡NUEVO! Este es el log más importante.
        // Registra el mensaje Y toda la traza de la excepción.
        Log::error($e->getMessage(), ['exception' => $e]);

        // ... (El resto del código de 500 que ya tenías) ...
        $errorMessage = (config('app.env') === 'production')
            ? 'Error interno del servidor.'
            : $e->getMessage(); // En dev, muestra el mensaje real

        return response()->json([
            'status'  => 500,
            'message' => $errorMessage,
            'data'    => null
        ], 500);
    });

})->create();
