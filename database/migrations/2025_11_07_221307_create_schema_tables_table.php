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
        Schema::create('schema_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schema_id')->constrained('schemas')->cascadeOnDelete();
            $table->string('table_name');
            $table->longText('definition');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schema_tables');
    }
};
