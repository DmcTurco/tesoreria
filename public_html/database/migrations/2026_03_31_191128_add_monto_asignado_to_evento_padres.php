<?php

use App\Models\EventoPadre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::table('evento_padres', function (Blueprint $table) {
			$table->decimal('monto_asignado', 10, 2)->nullable()->after('monto_pagado');
			$table->unsignedTinyInteger('ajuste_resuelto')->default(1)->after('monto_asignado');
			// 0 = pendiente de ajuste | 1 = sin ajuste / resuelto
		});

		// Poblar monto_asignado en registros existentes
		EventoPadre::whereNull('monto_asignado')
			->with('evento')
			->get()
			->each(function (EventoPadre $ep) {
				$ep->update([
					'monto_asignado' => $ep->evento->tiene_multa ? $ep->evento->multa_monto : null,
				]);
			});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('evento_padres', function (Blueprint $table) {
			$table->dropColumn(['monto_asignado', 'ajuste_resuelto']);
		});
	}
};
