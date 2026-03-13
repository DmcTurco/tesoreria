<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presupuesto;
use App\Models\Movimiento;
use Illuminate\Http\Request;

class PresupuestoController extends Controller
{
    // GET /api/presupuestos
    public function index(Request $request)
    {
        $query = Presupuesto::query();

        if ($request->filled('anio')) {
            $query->where('anio', $request->anio);
        }

        if ($request->filled('mes')) {
            $query->where('mes', $request->mes);
        }

        $presupuestos = $query->orderBy('anio')->orderBy('mes')->get();

        // Comparar con movimientos reales del mismo período
        $anio = $request->input('anio', now()->year);
        $mes  = $request->input('mes');

        $movQuery = Movimiento::whereYear('fecha', $anio);
        if ($mes) {
            $movQuery->whereMonth('fecha', $mes);
        }

        $gastosReales = $movQuery->where('tipo', Movimiento::TIPO_EGRESO)
            ->selectRaw('categoria, SUM(monto) as total')
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        return response()->json([
            'data'         => $presupuestos,
            'gastos_reales' => $gastosReales,
        ]);
    }

    // POST /api/presupuestos
    public function store(Request $request)
    {
        $request->validate([
            'anio'              => 'required|integer|min:2000|max:2100',
            'mes'               => 'nullable|integer|between:1,12',
            'categoria'         => 'required|string|max:100',
            'descripcion'       => 'nullable|string',
            'monto_planificado' => 'required|numeric|min:0',
        ]);

        $presupuesto = Presupuesto::create($request->only(
            'anio',
            'mes',
            'categoria',
            'descripcion',
            'monto_planificado'
        ));

        return response()->json([
            'message'      => 'Presupuesto registrado correctamente',
            'presupuesto'  => $presupuesto,
        ], 201);
    }

    // PUT /api/presupuestos/{id}
    public function update(Request $request, Presupuesto $presupuesto)
    {
        $request->validate([
            'categoria'         => 'sometimes|string|max:100',
            'descripcion'       => 'nullable|string',
            'monto_planificado' => 'sometimes|numeric|min:0',
        ]);

        $presupuesto->update($request->only(
            'categoria',
            'descripcion',
            'monto_planificado'
        ));

        return response()->json([
            'message'     => 'Presupuesto actualizado correctamente',
            'presupuesto' => $presupuesto,
        ]);
    }

    // DELETE /api/presupuestos/{id}
    public function destroy(Presupuesto $presupuesto)
    {
        $presupuesto->delete();

        return response()->json(['message' => 'Presupuesto eliminado correctamente']);
    }
}
