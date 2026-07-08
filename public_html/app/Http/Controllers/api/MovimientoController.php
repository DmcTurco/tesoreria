<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use Illuminate\Http\Request;

class MovimientoController extends Controller
{
    // GET /api/movimientos
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);

        $baseQuery = Movimiento::where('categoria', '!=', Movimiento::CAT_ANULACION);

        if ($request->filled('tipo')) {
            $baseQuery->where('tipo', $request->tipo);
        }

        if ($request->filled('categoria')) {
            $baseQuery->where('categoria', $request->categoria);
        }

        if ($request->filled('fecha_inicio')) {
            $baseQuery->whereDate('fecha', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $baseQuery->whereDate('fecha', '<=', $request->fecha_fin);
        }

        // Totales sobre el conjunto filtrado completo (antes de paginar)
        $totalIngresos = (clone $baseQuery)->where('tipo', Movimiento::TIPO_INGRESO)->sum('monto');
        $totalEgresos  = (clone $baseQuery)->where('tipo', Movimiento::TIPO_EGRESO)->sum('monto');

        $paginated = $baseQuery->with('registrador')
            ->orderByDesc('fecha')
            ->paginate($perPage);

        return response()->json([
            'data'           => $paginated->items(),
            'total'          => $paginated->total(),
            'total_ingresos' => $totalIngresos,
            'total_egresos'  => $totalEgresos,
            'saldo'          => $totalIngresos - $totalEgresos,
        ]);
    }

    // GET /api/movimientos/{id}
    public function show(Movimiento $movimiento)
    {
        return response()->json($movimiento->load('registrador'));
    }

    // POST /api/movimientos
    public function store(Request $request)
    {
        $request->validate([
            'tipo'          => 'required|integer|in:0,1',
            'monto'         => 'required|numeric|min:0.01',
            'descripcion'   => 'required|string|max:255',
            'categoria'     => 'required|integer',
            'fecha'         => 'required|date',
            'evento_id'     => 'nullable|exists:eventos,id',
            'comprobante'   => 'nullable|string|max:1000',
            'observaciones' => 'nullable|string',
        ]);

        $movimiento = Movimiento::create([
            'tipo'           => $request->tipo,
            'monto'          => $request->monto,
            'descripcion'    => $request->descripcion,
            'categoria'      => $request->categoria,
            'fecha'          => $request->fecha,
            'evento_id'      => $request->evento_id,
            'comprobante'    => $request->comprobante,
            'observaciones'  => $request->observaciones,
            'registrado_por' => $request->user()->id,
        ]);

        return response()->json([
            'message'     => 'Movimiento registrado correctamente',
            'movimiento'  => $movimiento,
        ], 201);
    }

    // PUT /api/movimientos/{id}
    public function update(Request $request, Movimiento $movimiento)
    {
        $request->validate([
            'descripcion'   => 'sometimes|string|max:255',
            'categoria'     => 'sometimes|integer',
            'fecha'         => 'sometimes|date',
            'evento_id'     => 'nullable|exists:eventos,id',
            'comprobante'   => 'nullable|string|max:1000',
            'observaciones' => 'nullable|string',
        ]);

        $movimiento->update($request->only(
            'descripcion',
            'categoria',
            'fecha',
            'evento_id',
            'comprobante',
            'observaciones'
        ));

        return response()->json([
            'message'    => 'Movimiento actualizado correctamente',
            'movimiento' => $movimiento,
        ]);
    }

    // DELETE /api/movimientos/{id}
    public function destroy(Movimiento $movimiento)
    {
        // Un movimiento que proviene de un abono no se elimina directamente:
        // hay que anular el abono para que la deuda vuelva a pendiente/parcial
        // y la caja quede cuadrada con su contra-asiento.
        if ($movimiento->abono_id) {
            return response()->json([
                'message' => 'Este movimiento proviene de un abono. Usa "Anular abono" para revertir el pago y que la deuda vuelva a estar pendiente.',
            ], 422);
        }

        $movimiento->delete();

        return response()->json(['message' => 'Movimiento eliminado correctamente']);
    }
}
