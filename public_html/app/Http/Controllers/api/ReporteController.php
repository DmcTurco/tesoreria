<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\EventoPadre;
use App\Models\Movimiento;
use App\Models\Multa;
use App\Models\Padre;
use App\Models\Evento;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    // GET /api/reportes/dashboard
    public function dashboard()
    {
        $anio = now()->year;
        $mes  = now()->month;

        // Caja: ingresos y egresos del mes actual
        $ingresosMes = Movimiento::where('tipo', Movimiento::TIPO_INGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->sum('monto');

        $egresosMes  = Movimiento::where('tipo', Movimiento::TIPO_EGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->sum('monto');

        // Saldo total histórico
        $totalIngresos = Movimiento::where('tipo', Movimiento::TIPO_INGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->sum('monto');
        $totalEgresos  = Movimiento::where('tipo', Movimiento::TIPO_EGRESO)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->sum('monto');

        // Multas pendientes — solo de padres activos
        $multasPendientes = Multa::where('estado', Multa::ESTADO_PENDIENTE)
            ->whereHas('padre', fn($q) => $q->where('retirado', false))
            ->sum('monto');
        $multasCount      = Multa::where('estado', Multa::ESTADO_PENDIENTE)
            ->whereHas('padre', fn($q) => $q->where('retirado', false))
            ->count();

        // Eventos activos
        $eventosActivos = Evento::where('estado', Evento::ESTADO_ACTIVO)->count();

        // Últimos movimientos
        $ultimosMovimientos = Movimiento::with('registrador')
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->orderByDesc('fecha')
            ->limit(5)
            ->get();

        return response()->json([
            'caja' => [
                'saldo_total'   => $totalIngresos - $totalEgresos,
                'ingresos_mes'  => $ingresosMes,
                'egresos_mes'   => $egresosMes,
                'saldo_mes'     => $ingresosMes - $egresosMes,
            ],
            'multas' => [
                'monto_pendiente' => $multasPendientes,
                'cantidad'        => $multasCount,
            ],
            'eventos_activos'     => $eventosActivos,
            'total_padres'        => Padre::where('retirado', false)->count(),
            'ultimos_movimientos' => $ultimosMovimientos,
        ]);
    }

    // ❌ deudores() eliminado → sin uso en la app; dependía de la tabla legacy `pagos`.
    //    El reporte de deudores usa /padres/con-deuda y /padres/{id}/deuda-detalle.

    // GET /api/reportes/movimientos-por-mes
    public function movimientosPorMes(Request $request)
    {
        $anio = $request->input('anio', now()->year);

        $data = Movimiento::whereYear('fecha', $anio)
            ->where('categoria', '!=', Movimiento::CAT_ANULACION)
            ->selectRaw('tipo, EXTRACT(MONTH FROM fecha)::int as mes, SUM(monto) as total')
            ->groupBy('tipo', 'mes')
            ->orderBy('mes')
            ->get();

        return response()->json($data);
    }

    // GET /api/reportes/deuda-matriz
    // Matriz de deuda: padres (filas) x eventos (columnas).
    // Misma lógica que Padre::saldoDeuda(): multas pendientes/parciales
    // + cuotas de eventos tipo CUOTA pendientes. Las multas sin evento
    // asociado se agrupan en una columna aparte "Multas (sin evento)".
    public function deudaMatriz(Request $request)
    {
        $padres = Padre::with(['multas.evento', 'eventoPadres.evento'])
            // Excluir retirados salvo que se pidan explícitamente (igual que /padres/con-deuda)
            ->when(!$request->boolean('con_retirados'), fn($q) => $q->where('retirado', false))
            ->get();

        $eventosMap = []; // key => ['key','id','titulo','fecha_inicio']
        $filas      = [];

        foreach ($padres as $padre) {
            $deudaPorEvento = []; // key evento => monto
            $total          = 0.0;

            // Multas pendientes o parciales
            foreach ($padre->multas as $multa) {
                if (!in_array($multa->estado, [Multa::ESTADO_PENDIENTE, Multa::ESTADO_PARCIAL])) {
                    continue;
                }
                $saldo = max(0, (float) $multa->monto - (float) ($multa->monto_pagado ?? 0));
                if ($saldo <= 0) {
                    continue;
                }

                if ($multa->evento) {
                    $key = 'evento_' . $multa->evento_id;
                    $eventosMap[$key] ??= [
                        'key'          => $key,
                        'id'           => $multa->evento_id,
                        'titulo'       => $multa->evento->titulo,
                        'fecha_inicio' => optional($multa->evento->fecha_inicio)->format('Y-m-d'),
                    ];
                } else {
                    $key = 'multas_sin_evento';
                    $eventosMap[$key] ??= [
                        'key'          => $key,
                        'id'           => null,
                        'titulo'       => 'Multas (sin evento)',
                        'fecha_inicio' => null,
                    ];
                }

                $deudaPorEvento[$key] = ($deudaPorEvento[$key] ?? 0) + $saldo;
                $total += $saldo;
            }

            // Cobros de eventos tipo CUOTA pendientes
            foreach ($padre->eventoPadres as $ep) {
                if ($ep->estado !== EventoPadre::ESTADO_PENDIENTE) {
                    continue;
                }
                if (!$ep->evento || $ep->evento->tipo !== Evento::TIPO_CUOTA) {
                    continue;
                }
                $saldo = (float) $ep->saldo_pendiente;
                if ($saldo <= 0) {
                    continue;
                }

                $key = 'evento_' . $ep->evento_id;
                $eventosMap[$key] ??= [
                    'key'          => $key,
                    'id'           => $ep->evento_id,
                    'titulo'       => $ep->evento->titulo,
                    'fecha_inicio' => optional($ep->evento->fecha_inicio)->format('Y-m-d'),
                ];

                $deudaPorEvento[$key] = ($deudaPorEvento[$key] ?? 0) + $saldo;
                $total += $saldo;
            }

            if ($total <= 0) {
                continue;
            }

            $filas[] = [
                'id'       => $padre->id,
                'codigo'   => $padre->codigo,
                'nombre'   => $padre->nombre,
                'hijo'     => $padre->hijo,
                'retirado' => (bool) $padre->retirado,
                'deuda'    => $deudaPorEvento, // key evento => monto
                'total'    => round($total, 2),
            ];
        }

        // Eventos ordenados por fecha (sin fecha, ej. "Multas sin evento", al final)
        $eventos = collect($eventosMap)
            ->sortBy(fn($e) => $e['fecha_inicio'] ?? '9999-99-99')
            ->values();

        usort($filas, fn($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'eventos' => $eventos,
            'padres'  => $filas,
        ]);
    }

    // GET /api/reportes/retirados-pendientes
    // Eventos/cuotas con asignación pendiente de un padre ya retirado
    // (situación que no debería ocurrir: el evento "debería evitar" a
    // los retirados). Marca si se puede limpiar directo o si tiene
    // pagos y requiere revisión manual — mismo criterio que se usó
    // para depurar el caso detectado en fix_retirados_eventos.sql.
    public function retiradosPendientes()
    {
        $items = EventoPadre::with(['evento', 'padre'])
            ->where('estado', EventoPadre::ESTADO_PENDIENTE)
            ->whereHas('padre', fn($q) => $q->where('retirado', true))
            ->get()
            ->map(function ($ep) {
                $tienePagos       = (float) $ep->monto_pagado > 0;
                $tieneAbonoActivo = Abono::where('tipo_deuda', Abono::TIPO_COBRO)
                    ->where('deuda_id', $ep->id)
                    ->where('estado', Abono::ESTADO_ACTIVO)
                    ->exists();

                return [
                    'evento_padre_id' => $ep->id,
                    'evento_id'       => $ep->evento_id,
                    'evento_titulo'   => optional($ep->evento)->titulo,
                    'evento_fecha'    => optional($ep->evento?->fecha_inicio)->format('Y-m-d'),
                    'padre_id'        => $ep->padre_id,
                    'padre_codigo'    => optional($ep->padre)->codigo,
                    'padre_nombre'    => optional($ep->padre)->nombre,
                    'fecha_retiro'    => optional($ep->padre?->fecha_retiro)->format('Y-m-d'),
                    'monto_asignado'  => (float) $ep->monto_asignado,
                    'monto_pagado'    => (float) $ep->monto_pagado,
                    'accion_sugerida' => (!$tienePagos && !$tieneAbonoActivo) ? 'eliminar_sin_riesgo' : 'revisar',
                ];
            })
            ->sortByDesc('evento_fecha')
            ->values();

        return response()->json($items);
    }
}
