<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
    // Esto captura el error que estás viendo ahora.
    $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {

        return response()->json([
            'status'  => 404,
            'message' => 'El recurso solicitado no existe',
            'data'    => null
        ], 404); // Código de estado HTTP 404
    });

    // 2. MANEJADOR PARA ERRORES DE VALIDACIÓN (422)
    // Esto capturará automáticamente los fallos de $request->validate()
    $exceptions->renderable(function (ValidationException $e, Request $request) {

        return response()->json([
            'status'  => 422,
            'message' => 'Los datos proporcionados no son válidos.',
            'data'    => $e->errors() // Muestra los campos que fallaron
        ], 422); // Código de estado HTTP 422
    });

    // 3. (OPCIONAL) MANEJADOR GENÉRICO PARA OTROS ERRORES (500)
    // Puedes añadir más manejadores, por ejemplo, para errores 500
    $exceptions->renderable(function (\Throwable $e, Request $request) {

        // Para depuración, puedes querer mostrar el error real
        $errorMessage = $e->getMessage();

        // PERO en producción, NUNCA expongas los detalles del error.
        if (config('app.env') === 'production') {
            $errorMessage = 'Error interno del servidor.';
        }

        return response()->json([
            'status'  => 500,
            'message' => $errorMessage,
            'data'    => null
        ], 500); // Código de estado HTTP 500
    });

})->create();
