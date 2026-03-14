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
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            // 0 = guardia | 1 = faena | 2 = reunion | 3 = cobro | 4 = actividad
            $table->unsignedTinyInteger('tipo');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();        // null = sin fecha límite (cobros)
            $table->time('hora_inicio')->nullable();      // null en cobros
            $table->time('hora_fin')->nullable();         // null en cobros
            $table->json('dias_semana')->nullable();      // solo guardias [1,2,3,4,5]
            $table->unsignedTinyInteger('padres_por_dia')->nullable(); // solo guardias
            $table->string('lugar')->nullable();
            $table->boolean('tiene_multa')->default(false);
            $table->decimal('multa_monto', 8, 2)->default(10.00);
            // 0 = activo | 1 = cerrado
            $table->unsignedTinyInteger('estado')->default(0);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos');
    }
};
