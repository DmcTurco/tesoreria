<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Multa;
use App\Models\EventoPadre;
use App\Services\CobroService;
use App\Services\PushNotificationService;
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

        $abonos = $query->orderByDesc('fecha')->get();

        // Pre-cargar EventoPadre de cobros activos en una sola query
        $cobroDeudaIds = $abonos
            ->where('estado', Abono::ESTADO_ACTIVO)
            ->where('tipo_deuda', 'cobro')
            ->pluck('deuda_id')
            ->unique();

        $eventoPadres = EventoPadre::whereIn('id', $cobroDeudaIds)
            ->get()
            ->keyBy('id');

        // Calcular total efectivo por padre
        // - Cobros: deduplicar por deuda_id, usar ep->monto_pagado (balance real tras devoluciones)
        // - Multas: sumar monto de cada abono directamente
        $acum = [];
        foreach ($abonos->where('estado', Abono::ESTADO_ACTIVO) as $abono) {
            $pid = $abono->padre_id;
            if (!isset($acum[$pid])) {
                $acum[$pid] = ['cobros' => [], 'multas' => 0.0];
            }
            if ($abono->tipo_deuda === 'cobro') {
                $did = $abono->deuda_id;
                if (!array_key_exists($did, $acum[$pid]['cobros'])) {
                    $ep = $eventoPadres->get($did);
                    $acum[$pid]['cobros'][$did] = (float) ($ep?->monto_pagado ?? $abono->monto);
                }
            } else {
                $acum[$pid]['multas'] += (float) $abono->monto;
            }
        }

        $totales = collect($acum)->map(
            fn($d) => round($d['multas'] + array_sum($d['cobros']), 2)
        );

        return response()->json([
            'abonos'            => $abonos->map(fn($a) => $this->formatAbono($a)),
            'totales_por_padre' => $totales,
        ]);
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
                'padre_id'       => $abono->padre_id,
            ]);

            $this->actualizarDeuda($request->tipo_deuda, $request->deuda_id);

            (new PushNotificationService())->enviarAPadre(
                $abono->padre,
                'Pago registrado',
                'Se registró un pago de S/ ' . number_format((float) $abono->monto, 2) . '.',
                ['tipo' => 'abono', 'abono_id' => (string) $abono->id]
            );
        });

        return response()->json(['success' => true, 'message' => 'Abono registrado correctamente.']);
    }

    // POST /api/abonos/multiples
    // Registra varios abonos de un mismo padre en una sola operación y envía
    // UNA sola notificación push consolidada (no una por cada deuda pagada).
    public function storeMultiples(Request $request)
    {
        $request->validate([
            'padre_id' => 'required|exists:padres,id',
            'fecha'    => 'required|date',
            'items'    => 'required|array|min:1',
            'items.*.tipo_deuda' => 'required|in:multa,cobro',
            'items.*.deuda_id'   => 'required|integer',
            'items.*.monto'      => 'required|numeric|min:0.01',
        ]);

        $padre = null;
        $totalRegistrado = 0.0;

        DB::transaction(function () use ($request, &$padre, &$totalRegistrado) {
            foreach ($request->items as $item) {
                $abono = Abono::create([
                    'padre_id'       => $request->padre_id,
                    'tipo_deuda'     => $item['tipo_deuda'],
                    'deuda_id'       => $item['deuda_id'],
                    'monto'          => $item['monto'],
                    'fecha'          => $request->fecha,
                    'registrado_por' => auth()->id(),
                    'estado'         => Abono::ESTADO_ACTIVO,
                ])->load('padre');

                $padre = $abono->padre;
                $totalRegistrado += (float) $abono->monto;

                $eventoId = null;
                if ($abono->tipo_deuda === 'cobro') {
                    $ep = EventoPadre::with('evento')->find($abono->deuda_id);
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
                    'padre_id'       => $abono->padre_id,
                ]);

                $this->actualizarDeuda($abono->tipo_deuda, $abono->deuda_id);
            }
        });

        if ($padre) {
            $cantidad = count($request->items);
            $cuerpo = $cantidad === 1
                ? 'Se registró un pago de S/ ' . number_format($totalRegistrado, 2) . '.'
                : "Se registró un pago de S/ " . number_format($totalRegistrado, 2) . " ({$cantidad} deudas).";

            (new PushNotificationService())->enviarAPadre(
                $padre,
                'Pago registrado',
                $cuerpo,
                ['tipo' => 'abono_multiple', 'cantidad' => (string) $cantidad]
            );
        }

        return response()->json([
            'success' => true,
            'message' => count($request->items) === 1
                ? 'Abono registrado correctamente.'
                : count($request->items) . ' abonos registrados correctamente.',
        ]);
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
                    'padre_id'              => $abono->padre_id,
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
                // padre_id directo; fallback por nombre solo para filas antiguas sin padre_id
                ->filter(fn($m) => $m->padre_id
                    ? $m->padre_id === $abono->padre_id
                    : str_contains($m->descripcion, $abono->padre->nombre))
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
        $ep = EventoPadre::findOrFail($id);
        (new CobroService())->sincronizar($ep);
    }

    private function marcarPerdonada(string $tipo, int $id): void
    {
        match ($tipo) {
            'multa' => Multa::where('id', $id)->update(['estado' => Multa::ESTADO_PAGADO]),
            'cobro' => EventoPadre::where('id', $id)->update(['estado' => EventoPadre::ESTADO_PRESENTE]),
        };
    }
}
