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
        Schema::create('evento_padres', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evento_id');
            $table->unsignedBigInteger('padre_id');
            $table->date('fecha')->nullable();           // solo guardias
            $table->json('turnos_estado')->nullable();   // null = sin turnos | [0,0] = ambos pendientes
            // 0 = pendiente | 1 = presente | 2 = ausente | 3 = justificado | 4 = exonerado
            $table->unsignedTinyInteger('estado')->default(0);
            $table->decimal('monto_pagado', 10, 2)->default(0);
            $table->decimal('monto_asignado', 10, 2)->nullable();
            $table->unsignedTinyInteger('ajuste_resuelto')->default(1); // 0=pendiente ajuste | 1=resuelto
            $table->timestamp('hora_marcado')->nullable();
            $table->boolean('multa_generada')->default(false);
            $table->text('motivo_exoneracion')->nullable();
            $table->unsignedBigInteger('exonerado_por')->nullable();
            $table->boolean('es_reemplazo')->default(false);
            $table->string('anotacion')->nullable(); // "Vino el tío Juan" / "Reemplazó María"
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['evento_id', 'padre_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evento_padres');
    }
};
