<?php

namespace Database\Seeders;

use App\Models\SchemaTable;
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
            'slug' => 'sandbox',
            'monthly_requests_limit' => 100,
            'rate_limit_per_minute' => 10,
            'price' => 0.00,
        ]);

        $basicPlan = \App\Models\Plan::create([
            'name' => 'basic',
            'slug' => 'basic',
            'monthly_requests_limit' => 1000,
            'rate_limit_per_minute' => 60,
            'price' => 9.99,
        ]);

        $proPlan = \App\Models\Plan::create([
            'name' => 'pro',
            'slug' => 'pro',
            'monthly_requests_limit' => 10000,
            'rate_limit_per_minute' => 120,
            'price' => 29.99,
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

        // Nuevo usuario Traro
        User::create([
            'id' => 5,
            'name' => 'Traro',
            'username' => 'traro',
            'company_name' => 'Asesorías Traro',
            'email' => 'asesoriastraro@gmail.com',
            'password' => '$2y$12$6wOzCk44z0WTkGphktHfc.YCHZlVjFVaRzDfLMyV6VaC/Ko/EB9x.',
            'status' => 'active',
            'plan_id' => 1,
            'monthly_requests_count' => 0,
            'monthly_token_count' => 0,
            'email_verified_at' => null,
            'remember_token' => null,
            'created_at' => '2025-12-04 14:12:17.000',
            'updated_at' => '2025-12-04 14:12:17.000'
        ]);

        // Crear token de acceso para el usuario admin (user_id = 2)
        \DB::table('personal_access_tokens')->insert([
            'id' => 1,
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => 2,
            'name' => 'auth_token',
            'token' => '6b9b18388e1489ae92e204f3e1da0b7f50577aeb05a9f3546f3c31ef00c9e4b3',
            'abilities' => '["*"]',
            'last_used_at' => '2025-11-09 00:12:30',
            'expires_at' => null,
            'created_at' => '2025-11-08 23:59:29',
            'updated_at' => '2025-11-09 00:12:30',
        ]);

        // Crear token de acceso para el usuario Traro (user_id = 5)
        \DB::table('personal_access_tokens')->insert([
            'id' => 7,
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => 5,
            'name' => 'auth_token',
            'token' => 'e1372212b6332aa07a41be2474a49a65f49b07d74c407aae774ab346d31dd71f',
            'abilities' => '["*"]',
            'last_used_at' => '2025-12-10 20:13:47.000',
            'expires_at' => null,
            'created_at' => '2025-12-10 20:13:13.000',
            'updated_at' => '2025-12-10 20:13:47.000',
        ]);

        // Crear esquema para el usuario Traro
        $traroUser = User::where('username', 'traro')->first();
        $schema = \App\Models\Schema::create([
            'id' => 16,
            'user_id' => $traroUser->id,
            'name' => 'grupoint_obi_cases',
            'dialect' => 'mariadb',
            'database_name_prefix' => '',
            'created_at' => '2025-12-10 20:13:47.000',
            'updated_at' => '2025-12-10 20:13:47.000',
        ]);

        // Crear tabla v_cases_details en el esquema
        SchemaTable::create([
            'id' => 16,
            'schema_id' => $schema->id,
            'table_name' => 'v_cases_details',
            'column_metadata' => [
                ['col' => 'id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'state', 'sql_def' => 'varchar(120)', 'desc' => 'Estado', 'instructions' => 'el valor de esta columna es un FQCN, el valor que se debe devolver la última palabra (después del último slash)', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'code', 'sql_def' => 'varchar(12)', 'desc' => 'Código, Caso', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'sent_to_acepta', 'sql_def' => 'tinyint(1)', 'desc' => 'Enviado a Acepta', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'created_at', 'sql_def' => 'datetime', 'desc' => 'Fecha creación', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'agreement_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'Convenio', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'property_address', 'sql_def' => 'varchar(255)', 'desc' => 'Dirección', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'inspection_date', 'sql_def' => 'date', 'desc' => 'Fecha inspección, Fecha visita', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'document_signing_date', 'sql_def' => 'date', 'desc' => 'Fecha firma documento', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'complaint_date', 'sql_def' => 'date', 'desc' => 'Fecha denuncia', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'collection_date', 'sql_def' => 'date', 'desc' => 'Fecha cobranza', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'budget_sending_date', 'sql_def' => 'date', 'desc' => 'Fecha envío presupuesto', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'settlement_report_date', 'sql_def' => 'date', 'desc' => 'Fecha informe liquidación', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'probable_payment_date', 'sql_def' => 'date', 'desc' => 'Fecha probable pago', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'online_collection_date', 'sql_def' => 'date', 'desc' => 'Fecha cobranza online', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'accident_number', 'sql_def' => 'varchar(50)', 'desc' => 'Número siniestro', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'bank_service_number', 'sql_def' => 'varchar(50)', 'desc' => 'Número de atención', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'advisory_amount', 'sql_def' => 'int(11)', 'desc' => 'Monto asesoría', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'accident_type_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'Tipo de siniestro', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'is_duplicated', 'sql_def' => 'tinyint(1)', 'desc' => 'Duplicado', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'date_of_loss', 'sql_def' => 'date', 'desc' => 'Fecha de siniestro', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'property_type', 'sql_def' => 'varchar(30)', 'desc' => 'Tipo de propiedad', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'contestation_date', 'sql_def' => 'date', 'desc' => 'Fecha de impugnación', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'customer_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Cliente', 'instructions' => 'FK de tabla customers', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'created_by', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Creador', 'instructions' => 'FK de tabla users, indica el uaurio que creó el registro', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'commune_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Comuna', 'instructions' => 'FK de tabla communes', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'consultant_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Consultor', 'instructions' => 'FK de tabla users, indica el asesor o técnico que realizó la visita', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'approved_amount', 'sql_def' => 'int(11)', 'desc' => 'Monto aprobado', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'uf_approved', 'sql_def' => 'float', 'desc' => 'UF aprobadas', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'amount_owed', 'sql_def' => 'int(11)', 'desc' => 'Deuda, Monto adeudado', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'amount_owed_including_vat', 'sql_def' => 'int(11)', 'desc' => 'Deuda con IVA', 'instructions' => '', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'amount_paid', 'sql_def' => 'int(11)', 'desc' => 'Monto pagado', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'bank_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Banco', 'instructions' => 'FK de tabla banks', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'insurer_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Aseguradora', 'instructions' => 'FK de tabla insurers', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'loss_adjuster_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Liquidadora', 'instructions' => 'FK de tabla loss_adjusters', 'is_default' => false, 'origin' => 'Caso'],
                ['col' => 'signature_status', 'sql_def' => "enum('generado','enviado a acepta','notificado','contrato pendiente','mandato pendiente','firmados')", 'desc' => 'Estado documentos', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'denounce_status', 'sql_def' => "enum('pendiente','en proceso','realizado')", 'desc' => 'Estado denuncia', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'scheduling_status', 'sql_def' => "enum('pendiente','en proceso','realizado')", 'desc' => 'Estado agendamiento', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'visit_status', 'sql_def' => "enum('pendiente','en proceso','realizado')", 'desc' => 'Estado visita', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'budget_status', 'sql_def' => "enum('pendiente','en proceso','realizado')", 'desc' => 'Estado presupuesto', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'decision_status', 'sql_def' => "enum('en espera','aprobado','bajo deducible','rechazado aseguradora','rechazado liquidadora','impugnado','rechazado')", 'desc' => 'Estado liquidación', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'payment_status', 'sql_def' => "enum('pendiente','cobranza','parcialmente pagado','pagado','cobranza online')", 'desc' => 'Estado pago, Estado cobranza, Estado recaudación', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'overall_status', 'sql_def' => "enum('en proceso','con pendientes','cerrado')", 'desc' => 'Estado general', 'instructions' => '', 'is_default' => true, 'origin' => 'Caso'],
                ['col' => 'customer_name', 'sql_def' => 'varchar(200)', 'desc' => 'Nombre Cliente', 'instructions' => '', 'is_default' => true, 'origin' => 'Cliente'],
                ['col' => 'customer_dni', 'sql_def' => 'varchar(20)', 'desc' => 'RUT Cliente', 'instructions' => '', 'is_default' => true, 'origin' => 'Cliente'],
                ['col' => 'phone', 'sql_def' => 'varchar(15)', 'desc' => 'Teléfono', 'instructions' => '', 'is_default' => true, 'origin' => 'Cliente'],
                ['col' => 'customer_email', 'sql_def' => 'varchar(100)', 'desc' => 'Email, Correo', 'instructions' => '', 'is_default' => false, 'origin' => 'Cliente'],
                ['col' => 'agent_id', 'sql_def' => 'bigint(20) unsigned', 'desc' => 'ID Agente', 'instructions' => 'FK de tabla users, indica el agente asignado al', 'is_default' => false, 'origin' => 'Cliente'],
                ['col' => 'agent_name', 'sql_def' => 'varchar(100)', 'desc' => 'Nombre Agente, Ejecutivo, Nombre Ejecutivo, Agente', 'instructions' => '', 'is_default' => false, 'origin' => 'Usuario'],
                ['col' => 'bank_name', 'sql_def' => 'varchar(50)', 'desc' => 'Banco', 'instructions' => '', 'is_default' => true, 'origin' => 'Banco'],
                ['col' => 'insurer_name', 'sql_def' => 'varchar(50)', 'desc' => 'Aseguradora', 'instructions' => '', 'is_default' => true, 'origin' => 'Aseguradora'],
                ['col' => 'loss_adjuster_name', 'sql_def' => 'varchar(50)', 'desc' => 'Liquidador', 'instructions' => '', 'is_default' => true, 'origin' => 'Liquidador'],
                ['col' => 'agreement_name', 'sql_def' => 'varchar(30)', 'desc' => 'Convenio', 'instructions' => '', 'is_default' => false, 'origin' => 'Convenio'],
                ['col' => 'accident_type_name', 'sql_def' => 'varchar(60)', 'desc' => 'Tipo siniestro, Siniestro', 'instructions' => '', 'is_default' => true, 'origin' => 'accident_types'],
                ['col' => 'consultant_name', 'sql_def' => 'varchar(100)', 'desc' => 'Asesor, Consultor, Técnico', 'instructions' => '', 'is_default' => true, 'origin' => 'Usuario'],
                ['col' => 'commune_name', 'sql_def' => 'varchar(100)', 'desc' => 'Comuna', 'instructions' => '', 'is_default' => true, 'origin' => 'Geografía']
            ]
        ]);
    }
}
