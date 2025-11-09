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
        Schema::create('prompt_histories', function (Blueprint $table) {
            $table->id();

            // Quién hizo la solicitud
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Información de la solicitud
            $table->longText('question'); // "Tráeme los casos..."
            $table->longText('schema_context'); // El string de "CREATE TABLE..." que enviamos
            $table->string('dialect');

            // Información de la respuesta
            $table->longText('raw_response'); // El JSON crudo de la IA
            $table->longText('generated_sql')->nullable(); // El SQL limpio, si fue exitoso
            $table->boolean('was_successful'); // ¿La API devolvió un SQL válido?
            $table->string('error_message')->nullable(); // "Ambigüedad", "Inseguro", etc.

            // Información de Costo (de la respuesta de la IA)
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompt_histories');
    }
};
