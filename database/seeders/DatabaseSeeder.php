<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Crear planes primero
        $sandboxPlan = \App\Models\Plan::create([
            'name' => 'sandbox',
            'monthly_request_limit' => 100,
            'rate_limit_per_minute' => 10,
            'is_active' => true,
        ]);

        $basicPlan = \App\Models\Plan::create([
            'name' => 'basic',
            'monthly_request_limit' => 1000,
            'rate_limit_per_minute' => 60,
            'is_active' => true,
        ]);

        $proPlan = \App\Models\Plan::create([
            'name' => 'pro',
            'monthly_request_limit' => 10000,
            'rate_limit_per_minute' => 120,
            'is_active' => true,
        ]);

        // Usuario de prueba
        User::factory()->create([
            'name' => 'Test User',
            'username' => 'testuser',
            'company_name' => 'Test Company',
            'email' => 'test@example.com',
            'status' => 'active',
            'plan_id' => $basicPlan->id,
        ]);

        // Usuario admin
        User::factory()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'company_name' => 'Admin Company',
            'email' => 'admin@mail.com',
            'password' => bcrypt('admin123'),
            'status' => 'active',
            'plan_id' => $proPlan->id,
        ]);

        // Usuario de prueba adicional
        User::factory()->create([
            'name' => 'Banco X',
            'username' => 'banco_x',
            'company_name' => 'Banco X S.A.',
            'email' => 'contacto@bancox.com',
            'password' => bcrypt('banco123'),
            'status' => 'active',
            'plan_id' => $proPlan->id,
        ]);
    }
}
