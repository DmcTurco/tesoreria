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
            $table->date('fecha');
            // 0 = pendiente | 1 = pagado | 2 = anulado
            $table->unsignedTinyInteger('estado')->default(0);
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
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
