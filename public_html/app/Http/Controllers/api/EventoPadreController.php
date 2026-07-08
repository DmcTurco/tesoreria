<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventoPadre;
use App\Services\CobroService;
use App\Services\PushNotificationService;

class EventoPadreController extends Controller
{
    // PUT /api/evento-padres/{id}/revertir-exoneracion  (role:0)
    // Revierte una exoneración o justificación hecha por error.
    // Restaura monto_pagado y estado desde los abonos activos (CobroService).
    public function revertirExoneracion(EventoPadre $eventoPadre)
    {
        if (!in_array($eventoPadre->estado, [EventoPadre::ESTADO_EXONERADO, EventoPadre::ESTADO_JUSTIFICADO])) {
            return response()->json(['message' => 'El registro no está exonerado ni justificado'], 422);
        }

        $eventoPadre->update([
            'estado'             => EventoPadre::ESTADO_PENDIENTE,
            'motivo_exoneracion' => null,
            'exonerado_por'      => null,
        ]);

        // Restaurar pago/estado desde los abonos activos
        (new CobroService())->sincronizar($eventoPadre->fresh());
        $eventoPadre->refresh();

        $evento = $eventoPadre->evento;

        if ($eventoPadre->padre && $evento) {
            (new PushNotificationService())->enviarAPadre(
                $eventoPadre->padre,
                'Exoneración revertida',
                "Tu exoneración de: {$evento->titulo} fue revertida. La asignación vuelve a estar vigente.",
                ['tipo' => 'asignacion', 'evento_id' => (string) $evento->id]
            );
        }

        return response()->json([
            'message'      => 'Exoneración revertida correctamente',
            'estado'       => $eventoPadre->estado,
            'monto_pagado' => (float) $eventoPadre->monto_pagado,
        ]);
    }
}
