<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login exitoso con credenciales correctas', function () {
    // Crear un usuario de prueba
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    // Hacer petición POST a /api/login con credenciales correctas
    $response = $this->postJson('/api/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    // Verificar respuesta 200
    $response->assertStatus(200)
             ->assertJson([
                 'status' => true,
                 'message' => 'Login exitoso',
             ])
             ->assertJsonStructure([
                 'status',
                 'message',
                 'data' => [
                     'access_token',
                     'token_type',
                     'user',
                 ],
             ]);
});

test('login fallido con credenciales incorrectas', function () {
    // Crear un usuario de prueba
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    // Hacer petición POST a /api/login con contraseña incorrecta
    $response = $this->postJson('/api/login', [
        'email' => 'test@example.com',
        'password' => 'wrongpassword',
    ]);

    // Verificar respuesta 401
    $response->assertStatus(401)
             ->assertJson([
                 'status' => false,
                 'message' => 'Las credenciales proporcionadas son incorrectas.',
             ]);
});

test('login fallido con email no existente', function () {
    // Hacer petición POST a /api/login con email no existente
    $response = $this->postJson('/api/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'password123',
    ]);

    // Verificar respuesta 401
    $response->assertStatus(401)
             ->assertJson([
                 'status' => false,
                 'message' => 'Las credenciales proporcionadas son incorrectas.',
             ]);
});

test('validación falla sin email', function () {
    // Hacer petición POST a /api/login sin email
    $response = $this->postJson('/api/login', [
        'password' => 'password123',
    ]);

    // Verificar respuesta 422 (validación falla)
    $response->assertStatus(422)
             ->assertJson([
                 'status' => false,
             ]);
});

test('validación falla sin password', function () {
    // Hacer petición POST a /api/login sin password
    $response = $this->postJson('/api/login', [
        'email' => 'test@example.com',
    ]);

    // Verificar respuesta 422 (validación falla)
    $response->assertStatus(422)
             ->assertJson([
                 'status' => false,
             ]);
});
