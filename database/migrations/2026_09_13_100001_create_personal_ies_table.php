<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_ies', function (Blueprint $table) {
            $table->id();
            $table->string('dni', 20)->unique();
            $table->string('nombres', 100);
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100)->nullable();
            $table->enum('tipo_personal', [
                'docente', 'auxiliar', 'administrativo', 'limpieza', 'vigilancia', 'directivo', 'otro',
            ]);
            $table->string('cargo', 150)->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('tolerancia_minutos')->default(5);
            $table->string('telefono', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ies');
    }
};
