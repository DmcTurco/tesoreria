<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina las tablas legacy `pagos` y `concepto_pagos` (nunca usadas, 0 filas).
 * El flujo real de cobros usa `abonos` + `movimientos`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('concepto_pagos');
    }

    public function down(): void
    {
        // No se recrean: eran tablas sin uso. Restaurar desde backup si hiciera falta.
    }
};
