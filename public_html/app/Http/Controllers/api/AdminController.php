<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    // POST /api/admin/migrate
    public function migrate()
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'Migración ejecutada correctamente',
                'output'  => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // POST /api/admin/fix-movimientos-anulados
    public function fixMovimientosAnulados()
    {
        try {
            // IDs de abonos anulados
            $abonosAnuladosIds = \App\Models\Abono::where('estado', 1)->pluck('id');

            // Movimientos de ingreso con categoría ABONO (0) cuyo abono está anulado
            $afectados = \App\Models\Movimiento::where('categoria', 0)
                ->whereIn('abono_id', $abonosAnuladosIds)
                ->update(['categoria' => 1]); // CAT_ANULACION

            return response()->json([
                'success' => true,
                'message' => "Corrección aplicada. {$afectados} movimiento(s) corregido(s).",
                'output'  => "Se marcaron {$afectados} movimiento(s) de abonos anulados con categoría=ANULACION.\nEsto corrige el total de ingresos en el resumen.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // POST /api/admin/migrate-fresh
    public function migrateFresh()
    {
        try {
            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'Base de datos reiniciada y seeders ejecutados',
                'output'  => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
