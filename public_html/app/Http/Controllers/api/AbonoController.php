<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Multa;
use App\Models\EventoPadre;
use App\Models\Movimiento;
use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AbonoController extends Controller
{
    // ── Registrar abono ───────────────────────────────────────────────────────
    // POST /api/abonos
    // Body: { padre_id, tipo_deuda, deuda_id, monto, fecha }
    public function store(Request $request)
    {
        $request->validate([
            'padre_id'   => 'required|exists:padres,id',
            'tipo_deuda' => 'required|in:multa,cobro,cuota',
            'deuda_id'   => 'required|integer',
            'monto'      => 'required|numeric|min:0.01',
            'fecha'      => 'required|date',
        ]);

        DB::transaction(function () use ($request) {
            // 1. Crear el abono
            $abono = Abono::create([
                'padre_id'      => $request->padre_id,
                'tipo_deuda'    => $request->tipo_deuda,
                'deuda_id'      => $request->deuda_id,
                'monto'         => $request->monto,
                'fecha'         => $request->fecha,
                'registrado_por' => auth()->id(),
                'estado'        => Abono::ESTADO_ACTIVO,
            ]);

            Movimiento::create([
                'tipo'           => Movimiento::TIPO_INGRESO,
                'monto'          => $abono->monto,
                'descripcion'    => 'Abono ' . $abono->tipo_deuda . ' - ' . $abono->padre->nombre,
                'categoria'      => 'Abono',
                'fecha'          => $abono->fecha,
                'registrado_por' => auth()->id(),
            ]);

            // 2. Actualizar monto_pagado y estado en la deuda origen
            $this->actualizarDeuda($request->tipo_deuda, $request->deuda_id);
        });

        return response()->json(['success' => true, 'message' => 'Abono registrado correctamente.']);
    }

    // ── Anular abono ──────────────────────────────────────────────────────────
    // POST /api/abonos/{id}/anular
    // Body: { motivo, perdonar_deuda }
    public function anular(Request $request, $id)
    {
        $request->validate([
            'motivo'         => 'required|string|max:255',
            'perdonar_deuda' => 'required|boolean',
        ]);

        $abono = Abono::findOrFail($id);

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

            if (!$request->boolean('perdonar_deuda')) {
                // Recalcular la deuda (descuenta este abono)
                $this->actualizarDeuda($abono->tipo_deuda, $abono->deuda_id);
            } else {
                // Perdonar → marcar la deuda como pagada de todas formas
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
        // Suma solo abonos activos de esta deuda
        $totalPagado = Abono::where('tipo_deuda', $tipo)
            ->where('deuda_id', $deudaId)
            ->where('estado', Abono::ESTADO_ACTIVO)
            ->sum('monto');

        match ($tipo) {
            'multa' => $this->actualizarMulta($deudaId, $totalPagado),
            'cobro' => $this->actualizarCobro($deudaId, $totalPagado),
            'cuota' => $this->actualizarCuota($deudaId, $totalPagado),
        };
    }

    private function actualizarMulta(int $id, float $pagado): void
    {
        $multa = Multa::findOrFail($id);
        $estado = match (true) {
            $pagado <= 0              => Multa::ESTADO_PENDIENTE,
            $pagado >= $multa->monto  => 2, // PAGADO
            default                   => 1, // PARCIAL
        };
        $multa->update(['monto_pagado' => $pagado, 'estado' => $estado]);
    }

    private function actualizarCobro(int $id, float $pagado): void
    {
        $ep    = EventoPadre::with('evento')->findOrFail($id);
        $total = (float) optional($ep->evento)->multa_monto;
        $estado = $pagado >= $total
            ? EventoPadre::ESTADO_PRESENTE   // pagado completo
            : EventoPadre::ESTADO_PENDIENTE; // pendiente o parcial

        $ep->update(['monto_pagado' => $pagado, 'estado' => $estado]);
    }

    private function actualizarCuota(int $id, float $pagado): void
    {
        $pago = Pago::findOrFail($id);
        $estado = match (true) {
            $pagado <= 0             => 0, // PENDIENTE
            $pagado >= $pago->monto  => 2, // PAGADO
            default                  => 1, // PARCIAL
        };
        $pago->update(['monto_pagado' => $pagado, 'estado' => $estado]);
    }

    private function marcarPerdonada(string $tipo, int $id): void
    {
        match ($tipo) {
            'multa' => Multa::where('id', $id)->update(['estado' => 2]),
            'cobro' => EventoPadre::where('id', $id)->update(['estado' => EventoPadre::ESTADO_PRESENTE]),
            'cuota' => Pago::where('id', $id)->update(['estado' => 2]),
        };
    }
}
