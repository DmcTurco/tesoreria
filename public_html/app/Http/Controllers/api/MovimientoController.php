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
            'comprobante'   => 'nullable|string|max:100',
            'observaciones' => 'nullable|string',
        ]);

        $movimiento = Movimiento::create([
            'tipo'           => $request->tipo,
            'monto'          => $request->monto,
            'descripcion'    => $request->descripcion,
            'categoria'      => $request->categoria,
            'fecha'          => $request->fecha,
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
            'comprobante'   => 'nullable|string|max:100',
            'observaciones' => 'nullable|string',
        ]);

        $movimiento->update($request->only(
            'descripcion',
            'categoria',
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
        $movimiento->delete();

        return response()->json(['message' => 'Movimiento eliminado correctamente']);
    }
}
