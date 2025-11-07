

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // CLAVE: Registrar nuestro middleware personalizado
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {

        // Forzar JSON en todas las rutas API
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*');
        });

        // Manejador específico para 401
        $exceptions->renderable(function (AuthenticationException $e, Request $request) {

            Log::warning('401 - Intento de acceso no autenticado: ' . $request->path());

            return response()->json([
                'status'  => false,
                'message' => 'No autenticado. El token es inválido o no se proporcionó.',
                'data'    => null
            ], 401);
        });

        // Manejador 404
        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {

            Log::warning('404 - Ruta no encontrada: ' . $request->path());

            return response()->json([
                'status'  => false,
                'message' => 'Recurso no encontrado',
                'data'    => null
            ], 404);
        });

        // Manejador 422
        $exceptions->renderable(function (ValidationException $e, Request $request) {

            Log::info('422 - Error de validación: ', $e->errors());

            return response()->json([
                'status'  => false,
                'message' => 'Los datos proporcionados no son válidos',
                'data'    => $e->errors()
            ], 422);
        });

        // Manejador 500
        $exceptions->renderable(function (\Throwable $e, Request $request) {

            Log::error($e->getMessage(), ['exception' => $e]);

            $errorMessage = (config('app.env') === 'production')
                ? 'Error interno del servidor.'
                : $e->getMessage();

            return response()->json([
                'status'  => false,
                'message' => $errorMessage,
                'data'    => null
            ], 500);
        });

    })->create();
