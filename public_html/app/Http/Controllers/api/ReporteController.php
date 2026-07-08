<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
