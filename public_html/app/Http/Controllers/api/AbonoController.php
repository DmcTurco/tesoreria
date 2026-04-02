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
            $query->orderByDesc('fecha')->get()->map(fn($a) => $this->formatAbono($a))
        );
    }

    // POST /api/abonos
    public function store(Request $request)
    {
        $request->validate([
            'padre_id'   => 'required|exists:padres,id',
            'tipo_deuda' => 'required|in:multa,cobro',
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

            $eventoId = null;
            if ($request->tipo_deuda === 'cobro') {
                $ep = EventoPadre::with('evento')->find($request->deuda_id);
                $eventoId = $ep?->evento_id;
            }

            Movimiento::create([
                'tipo'           => Movimiento::TIPO_INGRESO,
                'monto'          => $abono->monto,
                'descripcion'    => 'Abono ' . $abono->tipo_deuda . ' - ' . $abono->padre->nombre,
                'categoria'      => Movimiento::CAT_ABONO,
                'fecha'          => $abono->fecha,
                'registrado_por' => auth()->id(),
                'abono_id'       => $abono->id,
                'evento_id'      => $eventoId,
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

            $movimientoOriginal = Movimiento::where('abono_id', $abono->id)->first();

            if ($movimientoOriginal) {
                $movimientoOriginal->update(['categoria' => Movimiento::CAT_ANULACION]);

                Movimiento::create([
                    'tipo'                  => Movimiento::TIPO_EGRESO,
                    'monto'                 => $abono->monto,
                    'descripcion'           => 'Anulación abono ' . $abono->tipo_deuda . ' - ' . $abono->padre->nombre . ' | ' . $request->motivo,
                    'categoria'             => Movimiento::CAT_ANULACION,
                    'fecha'                 => now()->toDateString(),
                    'registrado_por'        => auth()->id(),
                    'abono_id'              => $abono->id,
                    'movimiento_anulado_id' => $movimientoOriginal->id,
                ]);
            }

            if (!$request->boolean('perdonar_deuda')) {
                $this->actualizarDeuda($abono->tipo_deuda, $abono->deuda_id);
            } else {
                $this->marcarPerdonada($abono->tipo_deuda, $abono->deuda_id);
            }

            // ← aquí, al final de la transacción
            if ($abono->tipo_deuda === 'cobro') {
                $ep = EventoPadre::with('evento')->find($abono->deuda_id);
                if ($ep && (float) $ep->monto_pagado === 0.0) {
                    $ep->update([
                        'ajuste_resuelto' => 1,
                        'monto_asignado'  => $ep->evento->multa_monto,
                    ]);
                }
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

    private function formatAbono(Abono $abono): array
    {
        $ep    = $abono->tipo_deuda === 'cobro'
            ? EventoPadre::with('evento:id,titulo')->find($abono->deuda_id)
            : null;
        $multa = $abono->tipo_deuda === 'multa'
            ? Multa::find($abono->deuda_id)
            : null;

        $ajustes   = [];
        $montoNeto = null;

        if ($ep) {
            $montoNeto = (float) $ep->monto_pagado;
            $ajustes   = Movimiento::where('evento_id', $ep->evento_id)
                ->whereNull('abono_id')
                ->where('categoria', Movimiento::CAT_CUOTA)
                ->where('created_at', '>=', $abono->created_at) // ← solo posteriores al abono
                ->get()
                ->filter(fn($m) => str_contains($m->descripcion, $abono->padre->nombre))
                ->values()
                ->map(fn($m) => [
                    'tipo'        => $m->tipo,
                    'monto'       => (float) $m->monto,
                    'descripcion' => $m->descripcion,
                    'fecha'       => $m->fecha,
                ])->toArray();
        }

        return [
            'id'         => $abono->id,
            'padre_id'   => $abono->padre_id,
            'padre'      => $abono->padre,
            'tipo_deuda' => $abono->tipo_deuda,
            'deuda_id'   => $abono->deuda_id,
            'monto'      => $abono->monto,
            'fecha'      => $abono->fecha,
            'estado'     => $abono->estado,
            'motivo_anulacion' => $abono->motivo_anulacion,
            'evento'     => $ep?->evento,
            'multa'      => $multa?->only(['id', 'concepto']),
            'ajustes'    => $ajustes,
            'monto_neto' => $montoNeto,
        ];
    }

    private function actualizarDeuda(string $tipo, int $deudaId): void
    {
        $totalPagado = Abono::where('tipo_deuda', $tipo)
            ->where('deuda_id', $deudaId)
            ->where('estado', Abono::ESTADO_ACTIVO)
            ->sum('monto');

        match ($tipo) {
            'multa' => $this->actualizarMulta($deudaId, $totalPagado),
            'cobro' => $this->actualizarCobro($deudaId, $totalPagado),
        };
    }

    private function actualizarMulta(int $id, float $pagado): void
    {
        $multa  = Multa::findOrFail($id);
        $estado = match (true) {
            $pagado <= 0             => Multa::ESTADO_PENDIENTE,
            $pagado >= $multa->monto => Multa::ESTADO_PAGADO,
            default                  => Multa::ESTADO_PARCIAL,
        };
        $multa->update(['monto_pagado' => $pagado, 'estado' => $estado]);
    }

    private function actualizarCobro(int $id, float $pagado): void
    {
        $ep    = EventoPadre::with('evento')->findOrFail($id);
        $total = (float) optional($ep->evento)->multa_monto;
        $estado = $pagado >= $total
            ? EventoPadre::ESTADO_PRESENTE
            : EventoPadre::ESTADO_PENDIENTE;

        $ep->update(['monto_pagado' => $pagado, 'estado' => $estado]);
    }

    private function marcarPerdonada(string $tipo, int $id): void
    {
        match ($tipo) {
            'multa' => Multa::where('id', $id)->update(['estado' => Multa::ESTADO_PAGADO]),
            'cobro' => EventoPadre::where('id', $id)->update(['estado' => EventoPadre::ESTADO_PRESENTE]),
        };
    }
}
