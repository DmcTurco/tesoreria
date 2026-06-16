<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\EventoPadre;
use Illuminate\Http\Request;

class EventoPadreController extends Controller
{
    // PUT /api/evento-padres/{id}/revertir-exoneracion  (role:0)
    // Revierte un evento_padre exonerado de vuelta a estado pendiente.
    public function revertirExoneracion(EventoPadre $eventoPadre)
    {
        if ($eventoPadre->estado !== EventoPadre::ESTADO_EXONERADO) {
            return response()->json(['message' => 'El registro no está exonerado'], 422);
        }

        $eventoPadre->update([
            'estado'             => EventoPadre::ESTADO_PENDIENTE,
            'motivo_exoneracion' => null,
            'exonerado_por'      => null,
        ]);

        return response()->json(['message' => 'Exoneración revertida correctamente']);
    }

    // public function registrarAsistencia(Request $request, Evento $evento)
    // {
    //     $request->validate([
    //         'padre_id'     => 'required|exists:padres,id',
    //         'es_reemplazo' => 'boolean',
    //         'anotacion'    => 'nullable|string|max:255',
    //     ]);

    //     $ep = EventoPadre::where('evento_id', $evento->id)
    //         ->where('padre_id', $request->padre_id)
    //         ->first();

    //     if (!$ep) {
    //         return response()->json(['message' => 'Padre no asignado'], 422);
    //     }

    //     $ep->update([
    //         'estado'       => EventoPadre::ESTADO_PRESENTE,
    //         'fecha'        => now()->toDateString(),
    //         'es_reemplazo' => $request->boolean('es_reemplazo'),
    //         'anotacion'    => $request->anotacion,
    //     ]);

    //     return response()->json(['message' => 'Asistencia registrada ✅']);
    // }

    // // PUT /evento-padres/{eventoPadre}/pagar  (role:0)
    // public function pagar(Request $request, EventoPadre $eventoPadre)
    // {
    //     $eventoPadre->update(['estado' => EventoPadre::ESTADO_PRESENTE]);

    //     return response()->json(['message' => 'Cobro marcado como pagado.']);
    // }
}
