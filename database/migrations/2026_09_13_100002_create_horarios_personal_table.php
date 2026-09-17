<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios_personal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_id')->constrained('personal_ies')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_semana');
            $table->time('hora_entrada');
            $table->time('hora_salida_maniana')->nullable();
            $table->time('hora_entrada_tarde')->nullable();
            $table->time('hora_salida');
            $table->unsignedInteger('tolerancia_minutos')->default(10);
            $table->boolean('activo')->default(true);
            $table->string('observacion')->nullable();
            $table->timestamps();

            $table->unique(['personal_id', 'dia_semana']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios_personal');
    }
};
