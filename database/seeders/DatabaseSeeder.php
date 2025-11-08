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

        // Crear esquema de ejemplo para el usuario admin
        $adminUser = User::where('username', 'admin')->first();
        $schema = \App\Models\Schema::create([
            'user_id' => $adminUser->id,
            'name' => 'Traro Obi Cases',
            'dialect' => 'mariadb',
        ]);

        // Crear tabla de casos en el esquema
        \App\Models\SchemaTable::create([
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
  PRIMARY KEY (`id`),
  KEY `cases_agreement_id_foreign` (`agreement_id`),
  KEY `cases_accident_type_id_foreign` (`accident_type_id`),
  CONSTRAINT `cases_accident_type_id_foreign` FOREIGN KEY (`accident_type_id`) REFERENCES `accident_types` (`id`),
  CONSTRAINT `cases_agreement_id_foreign` FOREIGN KEY (`agreement_id`) REFERENCES `agreements` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24623 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }
}
