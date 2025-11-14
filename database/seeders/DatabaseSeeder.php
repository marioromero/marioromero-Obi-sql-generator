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

        // Crear esquema de ejemplo para el usuario admin
        $adminUser = User::where('username', 'admin')->first();
        $schema = \App\Models\Schema::create([
            'user_id' => $adminUser->id,
            'name' => 'Traro Obi Cases',
            'dialect' => 'mariadb',
            'database_name_prefix' => 'grupoint_obi_cases',
        ]);

        // Crear tabla de casos en el esquema
       SchemaTable::create([
            'schema_id' => $schema->id,
            'table_name' => 'cases',
            'definition' => "CREATE TABLE `cases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `state` varchar(120) NOT NULL DEFAULT 'Ingreso',
  `code` varchar(12) DEFAULT NULL,
  `sent_to_acepta` tinyint(1) NOT NULL DEFAULT 0,
  `priority_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `agreement_id` bigint(20) unsigned DEFAULT NULL,
  `property_address` varchar(255) NOT NULL,
  `inspection_date` date DEFAULT NULL,
  `document_signing_date` date DEFAULT NULL,
  `complaint_date` date DEFAULT NULL,
  `collection_date` date DEFAULT NULL,
  `budget_sending_date` date DEFAULT NULL,
  `settlement_report_date` date DEFAULT NULL,
  `probable_payment_date` date DEFAULT NULL,
  `online_collection_date` date DEFAULT NULL,
  `accident_number` varchar(50) DEFAULT NULL,
  `bank_service_number` varchar(50) DEFAULT NULL,
  `advisory_amount` int(11) DEFAULT NULL,
  `accident_type_id` bigint(20) unsigned DEFAULT NULL,
  `is_duplicated` tinyint(1) NOT NULL DEFAULT 0,
  `description` longtext DEFAULT NULL,
  `resolution` longtext DEFAULT NULL,
  `comments_programming` longtext DEFAULT NULL,
  `date_of_loss` date DEFAULT NULL,
  `property_type` varchar(30) NOT NULL,
  `contestation_date` date DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `assigned_user` bigint(20) unsigned DEFAULT NULL,
  `commune_id` bigint(20) unsigned DEFAULT NULL,
  `consultant_id` bigint(20) unsigned DEFAULT NULL,
  `approved_amount` int(11) DEFAULT NULL,
  `uf_approved` float DEFAULT NULL,
  `amount_owed` int(11) DEFAULT NULL,
  `amount_owed_including_vat` int(11) DEFAULT NULL,
  `amount_paid` int(11) DEFAULT NULL,
  `bank_id` bigint(20) unsigned DEFAULT NULL,
  `insurer_id` bigint(20) unsigned DEFAULT NULL,
  `loss_adjuster_id` bigint(20) unsigned DEFAULT NULL,
  `signature_status` enum('generado','enviado a acepta','notificado','contrato pendiente','mandato pendiente','firmados') NOT NULL DEFAULT 'generado',
  `denounce_status` enum('pendiente','en proceso','realizado') DEFAULT NULL,
  `scheduling_status` enum('pendiente','en proceso','realizado') DEFAULT NULL,
  `visit_status` enum('pendiente','en proceso','realizado') DEFAULT NULL,
  `budget_status` enum('pendiente','en proceso','realizado') DEFAULT NULL,
  `decision_status` enum('en espera','aprobado','bajo deducible','rechazado aseguradora','rechazado liquidadora','impugnado','rechazado') DEFAULT NULL,
  `payment_status` enum('pendiente','cobranza','parcialmente pagado','pagado','cobranza online') DEFAULT NULL,
  `overall_status` enum('en proceso','con pendientes','cerrado') NOT NULL DEFAULT 'en proceso',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24623 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'column_metadata' => [
                ['col' => 'id', 'desc' => 'ID del Caso', 'sql_def' => '`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'is_default' => true, 'instructions' => null],
                ['col' => 'state', 'desc' => 'Estado del caso', 'sql_def' => "`state` varchar(120) NOT NULL DEFAULT 'Ingreso'", 'is_default' => false, 'instructions' => "IMPORTANTE: Los valores de esta columna tienen formato FQCN (ej: 'App\\Models\\State\\Ingreso'). Si buscas 'Ingreso', usa SIEMPRE: WHERE state LIKE '%Ingreso'"],
                ['col' => 'code', 'desc' => 'Código', 'sql_def' => '`code` varchar(12) DEFAULT NULL', 'is_default' => true, 'instructions' => null],
                ['col' => 'created_at', 'desc' => 'Creado', 'sql_def' => '`created_at` datetime NOT NULL DEFAULT current_timestamp()', 'is_default' => false, 'instructions' => null],
                ['col' => 'property_address', 'desc' => 'Direccion de la propiedad', 'sql_def' => '`property_address` varchar(255) NOT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'inspection_date', 'desc' => 'F Visita', 'sql_def' => '`inspection_date` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'document_signing_date', 'desc' => 'Fecha firma documentos', 'sql_def' => '`document_signing_date` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'complaint_date', 'desc' => 'Fecha de denuncia', 'sql_def' => '`complaint_date` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'collection_date', 'desc' => 'Fecha recaudacion', 'sql_def' => '`collection_date` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'budget_sending_date', 'desc' => 'Fecha envio presupuesto', 'sql_def' => '`budget_sending_date` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'settlement_report_date', 'desc' => 'Fecha informe de liquidacion', 'sql_def' => '`settlement_report_date` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'probable_payment_date', 'desc' => 'Fecha de pago', 'sql_def' => '`probable_payment_date` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'accident_number', 'desc' => 'Nro Siniestro', 'sql_def' => '`accident_number` varchar(50) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'bank_service_number', 'desc' => 'Nro atencion banco', 'sql_def' => '`bank_service_number` varchar(50) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'advisory_amount', 'desc' => 'Monto asesoria', 'sql_def' => '`advisory_amount` int(11) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'date_of_loss', 'desc' => 'Fecha del siniestro', 'sql_def' => '`date_of_loss` date DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'approved_amount', 'desc' => 'Monto aprobado', 'sql_def' => '`approved_amount` int(11) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'amount_owed', 'desc' => 'Monto adeudado', 'sql_def' => '`amount_owed` int(11) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'amount_owed_including_vat', 'desc' => 'Deuda mas IVA', 'sql_def' => '`amount_owed_including_vat` int(11) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'amount_paid', 'desc' => 'Monto pagado', 'sql_def' => '`amount_paid` int(11) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'payment_status', 'desc' => 'Recaudacion estado', 'sql_def' => "`payment_status` enum('pendiente','cobranza','parcialmente pagado','pagado','cobranza online') DEFAULT NULL", 'is_default' => false, 'instructions' => null],
                ['col' => 'overall_status', 'desc' => 'Estado general', 'sql_def' => "`overall_status` enum('en proceso','con pendientes','cerrado') NOT NULL DEFAULT 'en proceso'", 'is_default' => false, 'instructions' => null],
                ['col' => 'customer_id', 'desc' => 'ID del Cliente', 'sql_def' => '`customer_id` bigint(20) unsigned DEFAULT NULL', 'is_default' => true, 'instructions' => 'Usar para JOIN con la tabla customers'],
                ['col' => 'bank_id', 'desc' => 'ID del Banco', 'sql_def' => '`bank_id` bigint(20) unsigned DEFAULT NULL', 'is_default' => true, 'instructions' => 'Usar para JOIN con la tabla bancos'],
                ['col' => 'commune_id', 'desc' => 'ID de Comuna', 'sql_def' => '`commune_id` bigint(20) unsigned DEFAULT NULL', 'is_default' => true, 'instructions' => 'Usar para JOIN con la tabla comunas']
            ]
        ]);

        // 3. Crear la tabla 'customers'
        SchemaTable::create([
            'schema_id' => $schema->id,
            'table_name' => 'customers',
            'definition' => "CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `lastname` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `dni` varchar(20) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `serial_number` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `phone2` varchar(15) DEFAULT NULL,
  `gender` char(1) DEFAULT NULL,
  `marital_status` varchar(50) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `is_enabled` tinyint(1) DEFAULT 1,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `commune_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_agent` bigint(20) unsigned DEFAULT NULL,
  `comments` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_dni_unique` (`dni`)
) ENGINE=InnoDB AUTO_INCREMENT=8982 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'column_metadata' => [
                ['col' => 'id', 'desc' => 'ID del Cliente', 'sql_def' => '`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'is_default' => true, 'instructions' => null],
                ['col' => 'name', 'desc' => 'Nombre', 'sql_def' => '`name` varchar(100) NOT NULL', 'is_default' => true, 'instructions' => null],
                ['col' => 'lastname', 'desc' => 'Apellido', 'sql_def' => '`lastname` varchar(100) DEFAULT NULL', 'is_default' => true, 'instructions' => null],
                ['col' => 'full_name', 'desc' => 'Nombre completo', 'sql_def' => '`full_name` varchar(100) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'dni', 'desc' => 'RUT', 'sql_def' => '`dni` varchar(20) DEFAULT NULL', 'is_default' => true, 'instructions' => null],
                ['col' => 'username', 'desc' => 'Nombre de usuario', 'sql_def' => '`username` varchar(50) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'serial_number', 'desc' => 'Numero de serie', 'sql_def' => '`serial_number` varchar(15) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'email', 'desc' => 'Email', 'sql_def' => '`email` varchar(100) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'address', 'desc' => 'Dirección', 'sql_def' => '`address` varchar(255) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'phone', 'desc' => 'Teléfono', 'sql_def' => '`phone` varchar(15) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'phone2', 'desc' => 'Teléfono 2', 'sql_def' => '`phone2` varchar(15) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'gender', 'desc' => 'Género', 'sql_def' => '`gender` char(1) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'marital_status', 'desc' => 'Estado civil', 'sql_def' => '`marital_status` varchar(50) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'occupation', 'desc' => 'Profesión', 'sql_def' => '`occupation` varchar(100) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'nationality', 'desc' => 'Nacionalidad', 'sql_def' => '`nationality` varchar(50) DEFAULT NULL', 'is_default' => false, 'instructions' => null],
                ['col' => 'is_enabled', 'desc' => 'Habilitado', 'sql_def' => '`is_enabled` tinyint(1) DEFAULT 1', 'is_default' => false, 'instructions' => null],
                ['col' => 'commune_id', 'desc' => 'FK de Comuna', 'sql_def' => '`commune_id` bigint(20) unsigned DEFAULT NULL', 'is_default' => true, 'instructions' => 'Usar para JOIN con la tabla comunas'],
                ['col' => 'assigned_agent', 'desc' => 'Agente asignado (FK de users)', 'sql_def' => '`assigned_agent` bigint(20) unsigned DEFAULT NULL', 'is_default' => true, 'instructions' => 'Usar para JOIN con la tabla users'],
                ['col' => 'comments', 'desc' => 'Comentarios', 'sql_def' => '`comments` longtext DEFAULT NULL', 'is_default' => false, 'instructions' => null]
            ]
        ]);
    }
}
