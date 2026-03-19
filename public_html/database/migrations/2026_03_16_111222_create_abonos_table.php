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
        Schema::create('abonos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('padre_id');
            $table->enum('tipo_deuda', ['multa', 'cobro', 'cuota']);
            $table->unsignedBigInteger('deuda_id'); // id de multa / evento_padre / pago
            $table->decimal('monto', 10, 2);
            $table->date('fecha');
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->integer('estado')->default(0); // 0=activo, 1=anulado
            $table->text('motivo_anulacion')->nullable();
            $table->unsignedBigInteger('anulado_por')->nullable();
            $table->timestamp('anulado_at')->nullable();
            $table->boolean('deuda_perdonada')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abonos');
    }
};
