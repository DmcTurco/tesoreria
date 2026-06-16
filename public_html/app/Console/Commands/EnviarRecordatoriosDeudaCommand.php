<?php

namespace App\Console\Commands;

use App\Models\Evento;
use App\Models\EventoPadre;
use App\Models\Multa;
use App\Models\Padre;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;

/**
 * Envía un recordatorio push a los padres que tienen multas y/o cobros
 * (eventos) pendientes de pago, con el monto total adeudado.
 *
 * Programado en bootstrap/app.php (withSchedule) para ejecutarse
 * automáticamente los días martes y jueves a las 09:00.
 * También puede ejecutarse manualmente con: php artisan recordatorios:deudas
 */
class EnviarRecordatoriosDeudaCommand extends Command
{
    protected $signature = 'recordatorios:deudas';

    protected $description = 'Envía recordatorios push a los padres con multas o cobros pendientes de pago';

    public function handle(): int
    {
        $servicio = new PushNotificationService();
        $enviados = 0;

        Padre::whereHas('fcmTokens')->each(function (Padre $padre) use ($servicio, &$enviados) {
            // Multas pendientes o parciales (saldo > 0)
            $multasPendientes = Multa::where('padre_id', $padre->id)
                ->whereIn('estado', [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL])
                ->get()
                ->filter(fn (Multa $m) => $m->saldo() > 0);

            $totalMultas = $multasPendientes->sum(fn (Multa $m) => $m->saldo());

            // Cobros (eventos) pendientes: saldo_pendiente (monto_real - monto_pagado) > 0
            $cobrosPendientes = EventoPadre::where('padre_id', $padre->id)
                ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                ->whereHas('evento', fn ($q) => $q->where('tipo', Evento::TIPO_CUOTA))
                ->with('evento')
                ->get()
                ->filter(fn (EventoPadre $ep) => $ep->saldo_pendiente > 0);

            $totalCobros = $cobrosPendientes->sum(fn (EventoPadre $ep) => $ep->saldo_pendiente);

            $totalDeuda = $totalMultas + $totalCobros;
            $cantidadDeudas = $multasPendientes->count() + $cobrosPendientes->count();

            if ($totalDeuda <= 0 || $cantidadDeudas === 0) {
                return;
            }

            $cuerpo = $cantidadDeudas === 1
                ? 'Tienes un pago pendiente de S/ ' . number_format($totalDeuda, 2) . '.'
                : 'Tienes ' . $cantidadDeudas . ' pagos pendientes por un total de S/ ' . number_format($totalDeuda, 2) . '.';

            $servicio->enviarAPadre(
                $padre,
                'Recordatorio de pago',
                $cuerpo,
                ['tipo' => 'recordatorio_deuda', 'cantidad' => (string) $cantidadDeudas]
            );

            $enviados++;
        });

        $this->info("Recordatorios de deuda enviados: {$enviados}.");

        return self::SUCCESS;
    }
}
