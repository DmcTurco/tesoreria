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
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('padre_id');
            $table->unsignedBigInteger('concepto_pago_id')->nullable();
            $table->string('concepto');
            $table->decimal('monto', 8, 2);
            $table->decimal('monto_pagado', 10, 2)->default(0);
            $table->date('fecha');
            // 0 = pendiente | 1 = pagado | 2 = anulado
            $table->unsignedTinyInteger('estado')->default(0);
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->unsignedBigInteger('anulado_por')->nullable();
            $table->timestamp('anulado_at')->nullable();
            $table->boolean('deuda_perdonada')->default(false);
            $table->string('estado_deuda')->nullable(); // 'perdonada'
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
