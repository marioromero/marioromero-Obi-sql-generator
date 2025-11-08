<?php

use App\Http\Controllers\Api\V1\SchemaController;
use App\Http\Controllers\Api\V1\SchemaTableController;
use App\Http\Controllers\Api\V1\TranslateController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // Nuestra nueva ruta protegida: GET /api/me
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- GESTIÓN DE ESQUEMAS (CARPETAS) ---
    Route::apiResource('/schemas', SchemaController::class);

    // --- GESTIÓN DE TABLAS DE ESQUEMA (ARCHIVOS) ---
    // (Usamos rutas separadas para un CRUD de API simple)
    Route::post('/schema-tables', [SchemaTableController::class, 'store']);
    Route::get('/schema-tables/{schemaTable}', [SchemaTableController::class, 'show']);
    Route::put('/schema-tables/{schemaTable}', [SchemaTableController::class, 'update']);
    Route::delete('/schema-tables/{schemaTable}', [SchemaTableController::class, 'destroy']);

    Route::post('/translate', [TranslateController::class, 'translate']);

    // (Aquí irán tus futuras rutas protegidas: /logout, /projects, /tasks, etc.)
});
