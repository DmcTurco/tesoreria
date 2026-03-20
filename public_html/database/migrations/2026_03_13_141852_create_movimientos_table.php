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
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            // 0 = ingreso | 1 = egreso
            $table->unsignedTinyInteger('tipo');
            $table->decimal('monto', 10, 2);
            $table->string('descripcion')->nullable();
            $table->unsignedTinyInteger('categoria')->nullable();
            $table->date('fecha');
            $table->string('comprobante')->nullable();
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->unsignedTinyInteger('abono_id')->nullable();
            $table->unsignedTinyInteger('movimiento_anulado_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
