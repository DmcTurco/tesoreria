<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega padre_id a movimientos para vincular ajustes (devoluciones,
 * cobros extra) directamente al padre, en lugar de buscar su nombre
 * dentro de la descripción (frágil: nombres repetidos o renombrados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('padre_id')
                ->nullable()
                ->after('evento_id')
                ->constrained('padres')
                ->nullOnDelete();
        });

        // Backfill: movimientos con abono → padre del abono
        DB::statement('
            UPDATE movimientos m
            SET padre_id = (SELECT a.padre_id FROM abonos a WHERE a.id = m.abono_id)
            WHERE m.abono_id IS NOT NULL AND m.padre_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('padre_id');
        });
    }
};
