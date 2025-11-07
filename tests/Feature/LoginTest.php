<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login exitoso con credenciales correctas', function () {
    // Crear un plan primero
    $plan = \App\Models\Plan::factory()->create([
        'name' => 'basic',
        'monthly_request_limit' => 1000,
        'rate_limit_per_minute' => 60,
        'is_active' => true,
    ]);

    // Crear un usuario de prueba
    $user = User::factory()->create([
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'company_name' => 'Test Company',
        'status' => 'active',
        'plan_id' => $plan->id,
    ]);

    // Hacer petición POST a /api/login con credenciales correctas
    $response = $this->postJson('/api/login', [
        'username' => 'testuser',
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
    // Crear un plan primero
    $plan = \App\Models\Plan::factory()->create([
        'name' => 'basic',
        'monthly_request_limit' => 1000,
        'rate_limit_per_minute' => 60,
        'is_active' => true,
    ]);

    // Crear un usuario de prueba
    User::factory()->create([
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'company_name' => 'Test Company',
        'status' => 'active',
        'plan_id' => $plan->id,
    ]);

    // Hacer petición POST a /api/login con contraseña incorrecta
    $response = $this->postJson('/api/login', [
        'username' => 'testuser',
        'password' => 'wrongpassword',
    ]);

    // Verificar respuesta 401
    $response->assertStatus(401)
             ->assertJson([
                 'status' => false,
                 'message' => 'Las credenciales proporcionadas son incorrectas.',
             ]);
});

test('login fallido con username no existente', function () {
    // Hacer petición POST a /api/login con username no existente
    $response = $this->postJson('/api/login', [
        'username' => 'nonexistentuser',
        'password' => 'password123',
    ]);

    // Verificar respuesta 401
    $response->assertStatus(401)
             ->assertJson([
                 'status' => false,
                 'message' => 'Las credenciales proporcionadas son incorrectas.',
             ]);
});

test('validación falla sin username', function () {
    // Hacer petición POST a /api/login sin username
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
        'username' => 'testuser',
    ]);

    // Verificar respuesta 422 (validación falla)
    $response->assertStatus(422)
             ->assertJson([
                 'status' => false,
             ]);
});
