<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_personals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_id')->constrained('personal_ies')->cascadeOnDelete();
            $table->date('fecha');

            $table->time('hora_entrada_maniana')->nullable();
            $table->enum('estado_entrada', ['presente', 'tarde', 'ausente', 'justificado'])->nullable();

            $table->time('hora_salida_maniana')->nullable();
            $table->enum('estado_salida_maniana', ['normal', 'anticipada'])->nullable();

            $table->time('hora_entrada_tarde')->nullable();
            $table->enum('estado_entrada_tarde', ['presente', 'tarde', 'ausente'])->nullable();

            $table->time('hora_salida_tarde')->nullable();
            $table->enum('estado_salida', ['normal', 'anticipada'])->nullable();

            $table->string('observacion')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['personal_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_personals');
    }
};
