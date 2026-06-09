<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            // 0 = sin turnos, 1 = tiene 2 turnos (entrada y salida)
            $table->tinyInteger('tiene_turnos')->default(0)->after('tiene_multa');
        });

        Schema::table('evento_padres', function (Blueprint $table) {
            // null = sin turno, 1 = entrada, 2 = salida
            $table->tinyInteger('turno')->nullable()->after('fecha');
        });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn('tiene_turnos');
        });

        Schema::table('evento_padres', function (Blueprint $table) {
            $table->dropColumn('turno');
        });
    }
};
