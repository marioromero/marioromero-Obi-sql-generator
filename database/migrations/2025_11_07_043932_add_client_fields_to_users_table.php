<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('name');
            $table->string('company_name')->after('username');
            $table->enum('status', ['active', 'suspended', 'trial'])->default('trial')->after('company_name');
            $table->foreignId('plan_id')->constrained('plans')->after('status');
            $table->integer('monthly_requests_count')->default(0)->after('plan_id');
            $table->integer('minute_requests_count')->default(0)->after('monthly_requests_count');
            $table->timestamp('last_request_at')->nullable()->after('minute_requests_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn([
                'username',
                'company_name',
                'status',
                'plan_id',
                'monthly_requests_count',
                'minute_requests_count',
                'last_request_at'
            ]);
        });
    }
};
