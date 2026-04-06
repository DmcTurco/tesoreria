<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;

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
            $corregidos = 0;
            $log = [];

            // Estrategia 1: por abono_id (si la migration ya corrió)
            $movimientos = \App\Models\Movimiento::where('categoria', 0)
                ->whereNotNull('abono_id')
                ->get();

            foreach ($movimientos as $mov) {
                $abono = \App\Models\Abono::find($mov->abono_id);
                if ($abono && $abono->estado === 1) {
                    $mov->update(['categoria' => 1]);
                    $corregidos++;
                    $log[] = "[ID OK] Movimiento #{$mov->id} corregido via abono_id={$mov->abono_id}";
                }
            }

            // Estrategia 2: por descripción (para abono_id truncado/incorrecto)
            // Los movimientos de abono tienen descripción: "Abono cobro - Nombre" o "Abono multa - Nombre"
            $abonosAnulados = \App\Models\Abono::where('estado', 1)->with('padre')->get();

            foreach ($abonosAnulados as $abono) {
                if (!$abono->padre) continue;

                // Buscar por nombre del padre + monto (sin restricción de fecha)
                $movs = \App\Models\Movimiento::where('categoria', 0)
                    ->where('tipo', 0)
                    ->where('descripcion', 'like', '%' . $abono->padre->nombre . '%')
                    ->where('monto', $abono->monto)
                    ->get();

                foreach ($movs as $mov) {
                    $mov->update(['categoria' => 1]);
                    $corregidos++;
                    $log[] = "[DESC OK] Movimiento #{$mov->id} corregido via descripcion (abono #{$abono->id} - {$abono->padre->nombre})";
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Corrección aplicada. {$corregidos} movimiento(s) corregido(s).",
                'output'  => implode("\n", $log) ?: "No se encontraron movimientos para corregir.",
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
