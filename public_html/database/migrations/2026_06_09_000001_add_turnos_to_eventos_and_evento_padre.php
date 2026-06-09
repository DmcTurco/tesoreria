<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            // null = sin turnos | [0,0] = ambos pendientes | [1,0] = entrada ✓ | [1,1] = ambos ✓
            $table->json('turnos_estado')->nullable()->after('fecha');
        });

        // Todos los eventos de tipo bapers (tipo=0) tienen turnos
        DB::table('eventos')->where('tipo', 0)->update(['tiene_turnos' => 1]);

        // Inicializar turnos_estado en las filas de bapers existentes
        $ids = DB::table('eventos')->where('tipo', 0)->pluck('id');

        DB::table('evento_padres')
            ->whereIn('evento_id', $ids)
            ->get(['id', 'estado'])
            ->each(function ($ep) {
                $estadoEntrada = $ep->estado == 1 ? 1 : 0;
                DB::table('evento_padres')
                    ->where('id', $ep->id)
                    ->update(['turnos_estado' => json_encode([$estadoEntrada, 0])]);
            });
    }

    public function down(): void
    {
        Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn('tiene_turnos');
        });

        Schema::table('evento_padres', function (Blueprint $table) {
            $table->dropColumn('turnos_estado');
        });
    }
};
