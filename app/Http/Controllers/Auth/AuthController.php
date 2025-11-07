<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponser; // TRAIT
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response; // Para los códigos HTTP

class AuthController extends Controller
{
    // <-- 2. USAMOS NUESTRO TRAIT
    // Esto le da al controlador acceso a $this->sendResponse() y $this->sendError()
    use ApiResponser;

    /**
     * Maneja la solicitud de inicio de sesión (Login).
     */
    public function login(Request $request)
    {
        // 1. Validar los datos de entrada
        $request->validate([
            'username' => 'required|string',
            'password' => 'required',
        ]);

        // 2. Buscar al usuario por su username
        $user = User::where('username', $request->username)->first();

        // 3. Verificar al usuario y la contraseña
        if (! $user || ! Hash::check($request->password, $user->password)) {

            Log::info("Intento de login fallido para el username: {$request->username}");
            return $this->sendError(
                'Las credenciales proporcionadas son incorrectas.',
                Response::HTTP_UNAUTHORIZED // Código HTTP 401
            );
        }

        if ($user->status == 'suspended') {
            Log::warning("Intento de login de usuario suspendido: {$user->username} ");

            return $this->sendError(
                'Esta cuenta se encuentra suspendida.',
                Response::HTTP_FORBIDDEN // 403 Forbidden
            );
        }

        // 4. Si las credenciales son correctas, creamos el token
        $token = $user->createToken('auth_token')->plainTextToken;

        // 5. Devolvemos la respuesta usando nuestro Trait.
        return $this->sendResponse(
            data: [ // Enviamos el token y el usuario como 'data'
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ],
            message: 'Login exitoso',
            httpCode: Response::HTTP_OK // Código HTTP 200
        );
    }

    public function logout(Request $request)
    {
        // Obtener el usuario autenticado
        $user = $request->user();

        // Invalidar el token de acceso actual (el que se usó para esta petición)
        $user->currentAccessToken()->delete();

        // Registrar en el log
        Log::info("Logout exitoso para: {$user->username}");

        // Devolver respuesta exitosa
        return $this->sendResponse(
            data: null,
            message: 'Sesión cerrada exitosamente'
        );
    }

    public function me(Request $request)
    {
        return $this->sendResponse(
            data: $request->user(),
            message: 'Información del usuario obtenida'
        );
    }
}
