<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\EventoPadre;
use App\Models\Pago;
use App\Models\Movimiento;
use App\Models\Multa;
use App\Models\Padre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagoController extends Controller
{
    // GET /api/pagos
    public function index(Request $request)
    {
        $query = Pago::with('padre', 'conceptoPago', 'registrador');

        if ($request->filled('padre_id')) {
            $query->where('padre_id', $request->padre_id);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha', '<=', $request->fecha_fin);
        }

        return response()->json($query->orderByDesc('fecha')->get());
    }

    // GET /api/pagos/{id}
    public function show(Pago $pago)
    {
        return response()->json($pago->load('padre', 'conceptoPago', 'registrador'));
    }

    // POST /api/pagos
    public function store(Request $request)
    {
        $request->validate([
            'padre_id'         => 'required|integer|exists:padres,id',
            'concepto_pago_id' => 'nullable|integer|exists:concepto_pagos,id',
            'concepto'         => 'required|string|max:255',
            'monto'            => 'required|numeric|min:0.01',
            'fecha'            => 'required|date',
            'observaciones'    => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request) {
            $pago = Pago::create([
                'padre_id'         => $request->padre_id,
                'concepto_pago_id' => $request->concepto_pago_id,
                'concepto'         => $request->concepto,
                'monto'            => $request->monto,
                'fecha'            => $request->fecha,
                'estado'           => Pago::ESTADO_PAGADO,
                'observaciones'    => $request->observaciones,
                'registrado_por'   => $request->user()->id,
            ]);

            // Registrar automáticamente como ingreso en movimientos
            Movimiento::create([
                'tipo'           => Movimiento::TIPO_INGRESO,
                'monto'          => $pago->monto,
                'descripcion'    => $pago->concepto,
                'categoria'      => 'Cuota / Pago',
                'fecha'          => $pago->fecha,
                'registrado_por' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Pago registrado correctamente',
                'pago'    => $pago->load('padre'),
            ], 201);
        });
    }

    /**
     * GET /api/padres/con-deuda
     * Devuelve solo los padres que tienen deuda pendiente (multas + cuotas + cobros).
     * Incluye el monto total de deuda para mostrarlo en el selector.
     */
    public function padresConDeuda(Request $request)
    {
        $padres = Padre::with(['user', 'multas', 'pagos', 'eventoPadres.evento'])
            ->get()
            ->map(function ($padre) {
                // Multas pendientes
                $multas = $padre->multas
                    ->where('estado', Multa::ESTADO_PENDIENTE)
                    ->sum('monto');

                // Cuotas/pagos pendientes
                $cuotas = $padre->pagos
                    ->where('estado', Pago::ESTADO_PENDIENTE)
                    ->sum('monto');

                // Cobros de eventos pendientes
                $cobros = $padre->eventoPadres
                    ->where('estado', EventoPadre::ESTADO_PENDIENTE)
                    ->filter(fn($ep) => optional($ep->evento)->tipo === Evento::TIPO_COBRO)
                    ->sum(fn($ep) => optional($ep->evento)->multa_monto ?? 0);

                $totalDeuda = (float) ($multas + $cuotas + $cobros);

                return [
                    'id'          => $padre->id,
                    'nombre'      => $padre->nombre,
                    'hijo'        => $padre->hijo ?? '—',
                    'dni'         => $padre->dni,
                    'deuda_total' => $totalDeuda,
                    // Desglose por si quieres mostrarlo en el frontend
                    'desglose'    => [
                        'multas' => (float) $multas,
                        'cuotas' => (float) $cuotas,
                        'cobros' => (float) $cobros,
                    ],
                ];
            })
            // Solo los que deben algo
            ->filter(fn($p) => $p['deuda_total'] > 0)
            // Ordenados de mayor a menor deuda
            ->sortByDesc('deuda_total')
            ->values();

        return response()->json($padres);
    }

    public function anular(Request $request, $id)
    {
        // ── 1. Solo el Tesorero ────────────────────────────────────────────────
        if ($request->user()->role !== 0) {  // 0 = Tesorero
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        // ── 2. Validar body ────────────────────────────────────────────────────
        $request->validate([
            'motivo'         => 'required|string|max:255',
            'perdonar_deuda' => 'required|boolean',
        ]);

        // ── 3. Buscar el pago ──────────────────────────────────────────────────
        $pago = Pago::with('movimiento')->findOrFail($id);

        if ($pago->estado !== Pago::ESTADO_PAGADO) {
            return response()->json([
                'message' => 'Solo se pueden anular pagos en estado PAGADO.',
            ], 422);
        }

        // ── 4. Ejecutar en transacción ─────────────────────────────────────────
        DB::transaction(function () use ($pago, $request) {
            $perdonar = $request->boolean('perdonar_deuda');

            $pago->update([
                'estado'           => Pago::ESTADO_ANULADO,
                'motivo_anulacion' => $request->motivo,
                'anulado_por'      => auth()->id(),
                'anulado_at'       => now(),
                'deuda_perdonada'  => $perdonar,
            ]);

            if ($perdonar) {
                $pago->update(['estado_deuda' => 'perdonada']);
            } else {
                if ($pago->multa_id) {
                    Multa::where('id', $pago->multa_id)
                        ->update(['estado' => Multa::ESTADO_PENDIENTE]);
                }
                if ($pago->evento_padre_id) {
                    EventoPadre::where('id', $pago->evento_padre_id)
                        ->update(['estado' => EventoPadre::ESTADO_PENDIENTE]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => $request->boolean('perdonar_deuda')
                ? 'Pago anulado y deuda perdonada correctamente.'
                : 'Pago anulado. La deuda volvió a estado pendiente.',
        ]);
    }

}
