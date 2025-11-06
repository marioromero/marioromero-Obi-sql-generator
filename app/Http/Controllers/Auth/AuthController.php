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
        // Esto NO cambia. Si falla, nuestro handler en app.php (Paso 9)
        // lo capturará y devolverá el JSON de error 422 (status: false)
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 2. Buscar al usuario por su email
        $user = User::where('email', $request->email)->first();

        // 3. Verificar al usuario y la contraseña
        if (! $user || ! Hash::check($request->password, $user->password)) {

            Log::info("Intento de login fallido para el email: {$request->email}");
            return $this->sendError(
                'Las credenciales proporcionadas son incorrectas.',
                Response::HTTP_UNAUTHORIZED // Código HTTP 401
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
}
