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
        Schema::create('multas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('padre_id');
            $table->unsignedBigInteger('evento_id')->nullable();
            $table->decimal('monto', 8, 2)->default(10.00);
            $table->decimal('monto_pagado', 10, 2)->default(0);
            $table->string('concepto');
            $table->unsignedTinyInteger('estado')->default(0);
            // 0 = pendiente | 1 = parcial | 2 = pagado | 3 = exonerado | 4 = anulado
            $table->date('fecha_generada');
            $table->date('fecha_pagado')->nullable();
            $table->unsignedBigInteger('pagado_por')->nullable();
            $table->text('motivo_exoneracion')->nullable();
            $table->unsignedBigInteger('exonerado_por')->nullable();
            $table->timestamp('fecha_exoneracion')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multas');
    }
};
