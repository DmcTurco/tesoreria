<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\EventoPadre;
use App\Models\Multa;
use App\Models\Padre;
use Illuminate\Http\Request;

class PagoController extends Controller
{
    /**
     * GET /api/padres/con-deuda
     * Devuelve solo los padres que tienen deuda pendiente (multas + cobros).
     */
    public function padresConDeuda(Request $request)
    {
        $padres = Padre::with(['user', 'multas', 'eventoPadres.evento'])
            ->get()
            ->map(function ($padre) {
                // Multas pendientes o parciales
                $multas = $padre->multas
                    ->whereIn('estado', [Multa::ESTADO_PENDIENTE, 1]) // 1 = parcial
                    ->sum(fn($m) => max(0, (float) $m->monto - (float) ($m->monto_pagado ?? 0)));

                // Cobros de eventos pendientes o parciales
                $cobros = $padre->eventoPadres
                    ->whereIn('estado', [EventoPadre::ESTADO_PENDIENTE, 1]) // 1 = parcial
                    ->filter(fn($ep) => optional($ep->evento)->tipo === Evento::TIPO_CUOTA)
                    ->sum(fn($ep) => max(0, (float) ($ep->evento->multa_monto ?? 0) - (float) ($ep->monto_pagado ?? 0)));

                $totalDeuda = (float) ($multas + $cobros);

                return [
                    'id'          => $padre->id,
                    'nombre'      => $padre->nombre,
                    'hijo'        => $padre->hijo ?? '—',
                    'dni'         => $padre->dni,
                    'deuda_total' => $totalDeuda,
                    'desglose'    => [
                        'multas' => (float) $multas,
                        'cobros' => (float) $cobros,
                    ],
                ];
            })
            ->filter(fn($p) => $p['deuda_total'] > 0)
            ->sortByDesc('deuda_total')
            ->values();

        return response()->json($padres);
    }
}
