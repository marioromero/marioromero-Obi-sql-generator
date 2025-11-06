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

        Log::warning('404 - Ruta no encontrada: ' . $request->path());

        return response()->json([
            'status'  => false, // <-- CORREGIDO
            'message' => 'Recurso no encontrado',
            'data'    => null
        ], 404); // <-- Código HTTP real 404
    });

    // 2. MANEJADOR PARA ERRORES DE VALIDACIÓN (422)
    $exceptions->renderable(function (ValidationException $e, Request $request) {

        Log::info('422 - Error de validación: ', $e->errors());

        return response()->json([
            'status'  => false, // <-- CORREGIDO
            'message' => 'Los datos proporcionados no son válidos',
            'data'    => $e->errors()
        ], 422); // <-- Código HTTP real 422
    });

    // 3. MANEJADOR GENÉRICO PARA OTROS ERRORES (500)
    $exceptions->renderable(function (\Throwable $e, Request $request) {

        Log::error($e->getMessage(), ['exception' => $e]);

        $errorMessage = (config('app.env') === 'production')
            ? 'Error interno del servidor.'
            : $e->getMessage();

        return response()->json([
            'status'  => false, // <-- CORREGIDO
            'message' => $errorMessage,
            'data'    => null
        ], 500); // <-- Código HTTP real 500
    });

})->create();
