<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Movimiento;
use App\Models\Multa;
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

    // ❌ pagar() eliminado → se reemplaza por POST /api/abonos con tipo_deuda=multa

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
    // Anula la multa Y revierte sus abonos activos con contra-asiento en caja,
    // para que el dinero cobrado no quede "en el aire".
    public function anular(Request $request, Multa $multa)
    {
        $request->validate([
            'motivo' => 'required|string|max:500',
        ]);

        if ($multa->estado === Multa::ESTADO_ANULADO) {
            return response()->json(['message' => 'Esta multa ya fue anulada'], 422);
        }

        DB::transaction(function () use ($multa, $request) {
            // Revertir abonos activos de esta multa (mismo patrón que AbonoController::anular)
            $abonosActivos = Abono::with('padre')
                ->where('tipo_deuda', 'multa')
                ->where('deuda_id', $multa->id)
                ->where('estado', Abono::ESTADO_ACTIVO)
                ->get();

            foreach ($abonosActivos as $abono) {
                $abono->update([
                    'estado'           => Abono::ESTADO_ANULADO,
                    'motivo_anulacion' => 'Multa anulada: ' . $request->motivo,
                    'anulado_por'      => $request->user()->id,
                    'anulado_at'       => now(),
                ]);

                $movimientoOriginal = Movimiento::where('abono_id', $abono->id)->first();

                if ($movimientoOriginal) {
                    $movimientoOriginal->update(['categoria' => Movimiento::CAT_ANULACION]);

                    Movimiento::create([
                        'tipo'                  => Movimiento::TIPO_EGRESO,
                        'monto'                 => $abono->monto,
                        'descripcion'           => 'Anulación multa - ' . ($abono->padre->nombre ?? '') . ' | ' . $request->motivo,
                        'categoria'             => Movimiento::CAT_ANULACION,
                        'fecha'                 => now()->toDateString(),
                        'registrado_por'        => $request->user()->id,
                        'abono_id'              => $abono->id,
                        'movimiento_anulado_id' => $movimientoOriginal->id,
                        'evento_id'             => $multa->evento_id,
                        'padre_id'              => $abono->padre_id,
                    ]);
                }
            }

            $multa->update([
                'estado'        => Multa::ESTADO_ANULADO,
                'monto_pagado'  => 0,
                'observaciones' => $request->motivo,
            ]);
        });

        return response()->json(['message' => 'Multa anulada correctamente. Los abonos asociados fueron revertidos.']);
    }
}
