<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Multa;
use App\Models\EventoPadre;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AbonoController extends Controller
{
    // GET /api/abonos
    public function index(Request $request)
    {
        $query = Abono::with(['padre', 'registrador']);

        if ($request->filled('padre_id')) {
            $query->where('padre_id', $request->padre_id);
        }

        if ($request->filled('tipo_deuda')) {
            $query->where('tipo_deuda', $request->tipo_deuda);
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

        return response()->json(
            $query->orderByDesc('fecha')->get()
        );
    }

    // POST /api/abonos
    public function store(Request $request)
    {
        $request->validate([
            'padre_id'   => 'required|exists:padres,id',
            'tipo_deuda' => 'required|in:multa,cobro', // ❌ cuota eliminado
            'deuda_id'   => 'required|integer',
            'monto'      => 'required|numeric|min:0.01',
            'fecha'      => 'required|date',
        ]);

        DB::transaction(function () use ($request) {
            $abono = Abono::create([
                'padre_id'       => $request->padre_id,
                'tipo_deuda'     => $request->tipo_deuda,
                'deuda_id'       => $request->deuda_id,
                'monto'          => $request->monto,
                'fecha'          => $request->fecha,
                'registrado_por' => auth()->id(),
                'estado'         => Abono::ESTADO_ACTIVO,
            ])->load('padre');

            Movimiento::create([
                'tipo'           => Movimiento::TIPO_INGRESO,
                'monto'          => $abono->monto,
                'descripcion'    => 'Abono ' . $abono->tipo_deuda . ' - ' . $abono->padre->nombre,
                'categoria'      => Movimiento::CAT_ABONO,
                'fecha'          => $abono->fecha,
                'registrado_por' => auth()->id(),
                'abono_id'       => $abono->id,
            ]);

            $this->actualizarDeuda($request->tipo_deuda, $request->deuda_id);
        });

        return response()->json(['success' => true, 'message' => 'Abono registrado correctamente.']);
    }

    // POST /api/abonos/{id}/anular
    public function anular(Request $request, $id)
    {
        $request->validate([
            'motivo'         => 'required|string|max:255',
            'perdonar_deuda' => 'required|boolean',
        ]);

        $abono = Abono::with('padre')->findOrFail($id);

        if ($abono->estado === Abono::ESTADO_ANULADO) {
            return response()->json(['message' => 'Este abono ya fue anulado.'], 422);
        }

        DB::transaction(function () use ($abono, $request) {
            $abono->update([
                'estado'           => Abono::ESTADO_ANULADO,
                'motivo_anulacion' => $request->motivo,
                'anulado_por'      => auth()->id(),
                'anulado_at'       => now(),
                'deuda_perdonada'  => $request->boolean('perdonar_deuda'),
            ]);

            // 1. Marcar el movimiento original como anulado
            $movimientoOriginal = Movimiento::where('abono_id', $abono->id)->first();

            if ($movimientoOriginal) {
                // 1. Marcar el original como anulado
                $movimientoOriginal->update(['categoria' => Movimiento::CAT_ANULACION]);

                // 2. Crear el nuevo que explica la anulación
                Movimiento::create([
                    'tipo'                  => Movimiento::TIPO_EGRESO,
                    'monto'                 => $abono->monto,
                    'descripcion'           => 'Anulación abono ' . $abono->tipo_deuda . ' - ' . $abono->padre->nombre . ' | ' . $request->motivo,
                    'categoria'             => Movimiento::CAT_ANULACION,
                    'fecha'                 => now()->toDateString(),
                    'registrado_por'        => auth()->id(),
                    'abono_id'              => $abono->id,            // ← mismo abono
                    'movimiento_anulado_id' => $movimientoOriginal->id, // ← referencia al original
                ]);
            }

            if (!$request->boolean('perdonar_deuda')) {
                $this->actualizarDeuda($abono->tipo_deuda, $abono->deuda_id);
            } else {
                $this->marcarPerdonada($abono->tipo_deuda, $abono->deuda_id);
            }
        });

        return response()->json([
            'success' => true,
            'message' => $request->boolean('perdonar_deuda')
                ? 'Abono anulado y deuda perdonada.'
                : 'Abono anulado. La deuda volvió a pendiente o parcial.',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function actualizarDeuda(string $tipo, int $deudaId): void
    {
        $totalPagado = Abono::where('tipo_deuda', $tipo)
            ->where('deuda_id', $deudaId)
            ->where('estado', Abono::ESTADO_ACTIVO)
            ->sum('monto');

        match ($tipo) {
            'multa' => $this->actualizarMulta($deudaId, $totalPagado),
            'cobro' => $this->actualizarCobro($deudaId, $totalPagado),
        }; // ❌ cuota eliminado
    }

    private function actualizarMulta(int $id, float $pagado): void
    {
        $multa  = Multa::findOrFail($id);
        $estado = match (true) {
            $pagado <= 0             => Multa::ESTADO_PENDIENTE,
            $pagado >= $multa->monto => Multa::ESTADO_PAGADO,
            default                  => Multa::ESTADO_PARCIAL,   // ← ahora con constante
        };
        $multa->update(['monto_pagado' => $pagado, 'estado' => $estado]);
    }

    private function actualizarCobro(int $id, float $pagado): void
    {
        $ep     = EventoPadre::with('evento')->findOrFail($id);
        $total  = (float) optional($ep->evento)->multa_monto;
        $estado = $pagado >= $total
            ? EventoPadre::ESTADO_PRESENTE   // pagado completo
            : EventoPadre::ESTADO_PENDIENTE; // pendiente o parcial

        $ep->update(['monto_pagado' => $pagado, 'estado' => $estado]);
    }

    // ❌ actualizarCuota() eliminado

    private function marcarPerdonada(string $tipo, int $id): void
    {
        match ($tipo) {
            'multa' => Multa::where('id', $id)->update(['estado' => Multa::ESTADO_PAGADO]),      // ← era 2
            'cobro' => EventoPadre::where('id', $id)->update(['estado' => EventoPadre::ESTADO_PRESENTE]),
        };
    }
}
