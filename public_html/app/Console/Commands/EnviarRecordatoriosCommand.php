<?php

namespace App\Console\Commands;

use App\Models\Evento;
use App\Models\EventoPadre;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;

/**
 * Envía recordatorios push a los padres que tienen guardia o faena
 * asignada para el día siguiente.
 *
 * Programado en bootstrap/app.php (withSchedule) para ejecutarse
 * automáticamente todos los días a las 20:00. También puede ejecutarse
 * manualmente con: php artisan recordatorios:enviar
 */
class EnviarRecordatoriosCommand extends Command
{
    protected $signature = 'recordatorios:enviar';

    protected $description = 'Envía recordatorios push a los padres con guardia o faena programada para mañana';

    public function handle(): int
    {
        $mañana = now()->addDay()->toDateString();

        $eventoPadres = EventoPadre::with(['padre', 'evento'])
            ->whereDate('fecha', $mañana)
            ->whereIn('estado', [EventoPadre::ESTADO_PENDIENTE, EventoPadre::ESTADO_AUSENTE])
            ->get()
            ->filter(function (EventoPadre $ep) {
                return $ep->evento && ($ep->evento->esGuardia() || $ep->evento->esFaena());
            });

        if ($eventoPadres->isEmpty()) {
            $this->info("No hay guardias ni faenas programadas para {$mañana}.");
            return self::SUCCESS;
        }

        $servicio = new PushNotificationService();
        $enviados = 0;

        foreach ($eventoPadres as $ep) {
            if (!$ep->padre) {
                continue;
            }

            $evento = $ep->evento;
            $tipoTexto = $evento->esGuardia() ? 'guardia' : 'faena';
            $fechaTexto = $ep->fecha->format('d/m/Y');

            $servicio->enviarAPadre(
                $ep->padre,
                'Recordatorio: ' . ucfirst($tipoTexto) . ' mañana',
                "Mañana ({$fechaTexto}) tienes {$tipoTexto}: {$evento->titulo}.",
                ['tipo' => 'recordatorio', 'evento_id' => (string) $evento->id]
            );

            $enviados++;
        }

        $this->info("Recordatorios enviados: {$enviados}.");

        return self::SUCCESS;
    }
}
