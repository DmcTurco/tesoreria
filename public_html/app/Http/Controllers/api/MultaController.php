<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Multa;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MultaController extends Controller
{
    // GET /api/multas
    public function index(Request $request)
    {
        $query = Multa::with('padre', 'evento', 'pagador', 'exonerador');

        if ($request->filled('padre_id')) {
            $query->where('padre_id', $request->padre_id);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('evento_id')) {
            $query->where('evento_id', $request->evento_id);
        }

        return response()->json($query->orderByDesc('fecha_generada')->get());
    }

    // GET /api/multas/{id}
    public function show(Multa $multa)
    {
        return response()->json($multa->load('padre', 'evento', 'pagador', 'exonerador'));
    }

    // POST /api/multas/{id}/pagar
    public function pagar(Request $request, Multa $multa)
    {
        if ($multa->estado !== Multa::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'La multa no está en estado pendiente'], 422);
        }

        return DB::transaction(function () use ($request, $multa) {
            $multa->update([
                'estado'       => Multa::ESTADO_PAGADO,
                'fecha_pagado' => now()->toDateString(),
                'pagado_por'   => $request->user()->id,
            ]);

            // Registrar automáticamente como ingreso en movimientos
            Movimiento::create([
                'tipo'           => Movimiento::TIPO_INGRESO,
                'monto'          => $multa->monto,
                'descripcion'    => $multa->concepto,
                'categoria'      => 'Multa',
                'fecha'          => now()->toDateString(),
                'registrado_por' => $request->user()->id,
            ]);

            return response()->json(['message' => 'Multa cobrada y registrada como ingreso']);
        });
    }

    // POST /api/multas/{id}/exonerar
    public function exonerar(Request $request, Multa $multa)
    {
        $request->validate([
            'motivo_exoneracion' => 'required|string|max:500',
        ]);

        if ($multa->estado !== Multa::ESTADO_PENDIENTE) {
            return response()->json(['message' => 'Solo se pueden exonerar multas pendientes'], 422);
        }

        $multa->update([
            'estado'             => Multa::ESTADO_EXONERADO,
            'motivo_exoneracion' => $request->motivo_exoneracion,
            'exonerado_por'      => $request->user()->id,
            'fecha_exoneracion'  => now(),
        ]);

        return response()->json(['message' => 'Multa exonerada correctamente']);
    }

    // POST /api/multas/{id}/anular
    public function anular(Request $request, Multa $multa)
    {
        $request->validate([
            'motivo' => 'required|string|max:500',
        ]);

        $multa->update([
            'estado'        => Multa::ESTADO_ANULADO,
            'observaciones' => $request->motivo,
        ]);

        return response()->json(['message' => 'Multa anulada correctamente']);
    }
}
