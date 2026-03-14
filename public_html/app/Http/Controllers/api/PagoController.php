<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pago;
use App\Models\Movimiento;
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

    // PUT /api/pagos/{id}/anular
    public function anular(Request $request, Pago $pago)
    {
        if ($pago->estado === Pago::ESTADO_ANULADO) {
            return response()->json(['message' => 'El pago ya está anulado'], 422);
        }

        $pago->update([
            'estado'        => Pago::ESTADO_ANULADO,
            'observaciones' => $request->input('motivo', 'Anulado por tesorero'),
        ]);

        return response()->json(['message' => 'Pago anulado correctamente']);
    }
}
