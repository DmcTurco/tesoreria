<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\Multa;
use App\Models\Padre;
use App\Models\Pago;
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

        // Multas pendientes
        $multasPendientes = Multa::where('estado', Multa::ESTADO_PENDIENTE)->sum('monto');
        $multasCount      = Multa::where('estado', Multa::ESTADO_PENDIENTE)->count();

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
            'total_padres'        => Padre::count(),
            'ultimos_movimientos' => $ultimosMovimientos,
        ]);
    }

    // GET /api/reportes/deudores
    public function deudores()
    {
        $padres = Padre::with([
            'multas'  => fn($q) => $q->where('estado', Multa::ESTADO_PENDIENTE),
            'pagos'   => fn($q) => $q->where('estado', Pago::ESTADO_PENDIENTE),
        ])->get()
            ->map(function ($padre) {
                $deudaMultas  = $padre->multas->sum('monto');
                $deudaPagos   = $padre->pagos->sum('monto');
                $deudaTotal   = $deudaMultas + $deudaPagos;

                return [
                    'id'           => $padre->id,
                    'codigo'       => $padre->codigo,
                    'nombre'       => $padre->nombre,
                    'hijo'         => $padre->hijo,
                    'grado'        => $padre->grado,
                    'deuda_multas' => $deudaMultas,
                    'deuda_pagos'  => $deudaPagos,
                    'deuda_total'  => $deudaTotal,
                ];
            })
            ->filter(fn($p) => $p['deuda_total'] > 0)
            ->sortByDesc('deuda_total')
            ->values();

        return response()->json($padres);
    }

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
}
