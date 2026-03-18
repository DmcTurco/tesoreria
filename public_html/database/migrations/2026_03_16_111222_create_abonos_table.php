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
            $table->foreignId('padre_id')->constrained('padres')->cascadeOnDelete();
            $table->enum('tipo_deuda', ['multa', 'cobro', 'cuota']);
            $table->unsignedBigInteger('deuda_id'); // id de multa / evento_padre / pago
            $table->decimal('monto', 10, 2);
            $table->date('fecha');
            $table->foreignId('registrado_por')->constrained('users');
            $table->integer('estado')->default(0); // 0=activo, 1=anulado
            $table->text('motivo_anulacion')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->boolean('deuda_perdonada')->default(false);
            $table->timestamps();
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
