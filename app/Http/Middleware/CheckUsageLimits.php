<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimits
{
    use ApiResponser;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Cargar la relación 'plan' del usuario.
        // Usamos loadMissing() para evitar recargarla si ya lo estaba.
        $user->loadMissing('plan');

        if (!$user->plan) {
            // Caso de seguridad: el usuario no tiene un plan asignado.
            return $this->sendError(
                'Tu cuenta no tiene un plan de uso activo.',
                Response::HTTP_FORBIDDEN // 403 Forbidden
            );
        }

        // --- Verificación de Límite de Peticiones ---
        $requestLimit = $user->plan->monthly_request_limit;
        if ($requestLimit > 0 && $user->monthly_requests_count >= $requestLimit) {
            return $this->sendError(
                'Has alcanzado tu límite mensual de peticiones.',
                Response::HTTP_TOO_MANY_REQUESTS // 429 Too Many Requests
            );
        }

        // --- Verificación de Límite de Tokens ---
        $tokenLimit = $user->plan->monthly_token_limit;
        if ($tokenLimit > 0 && $user->monthly_token_count >= $tokenLimit) {
            return $this->sendError(
                'Has alcanzado tu límite mensual de tokens.',
                Response::HTTP_TOO_MANY_REQUESTS // 429 Too Many Requests
            );
        }

        // Si pasa todas las verificaciones, permite que la solicitud continúe.
        return $next($request);
    }
}
