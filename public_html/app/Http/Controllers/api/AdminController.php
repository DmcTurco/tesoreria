<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CobroService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

            // Estrategia 2: por descripción (solo para movimientos huérfanos sin abono_id válido)
            $abonosAnulados = \App\Models\Abono::where('estado', 1)->with('padre')->get();
            $abonosActivosIds = \App\Models\Abono::where('estado', 0)->pluck('id')->toArray();

            foreach ($abonosAnulados as $abono) {
                if (!$abono->padre) continue;

                // Solo movimientos sin abono_id o con abono_id que NO apunta a un abono activo
                $movs = \App\Models\Movimiento::where('categoria', 0)
                    ->where('tipo', 0)
                    ->where('descripcion', 'like', '%' . $abono->padre->nombre . '%')
                    ->where('monto', $abono->monto)
                    ->where(function ($q) use ($abonosActivosIds) {
                        $q->whereNull('abono_id')
                          ->orWhereNotIn('abono_id', $abonosActivosIds);
                    })
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

    // POST /api/admin/restaurar-movimientos
    public function restaurarMovimientos()
    {
        try {
            $abonosActivosIds = \App\Models\Abono::where('estado', 0)->pluck('id')->toArray();

            $movimientos = \App\Models\Movimiento::where('categoria', 1)
                ->where('tipo', 0)
                ->whereNotNull('abono_id')
                ->whereIn('abono_id', $abonosActivosIds)
                ->get();

            $count = count($movimientos);
            \App\Models\Movimiento::whereIn('id', $movimientos->pluck('id')->toArray())
                ->update(['categoria' => 0]);

            return response()->json([
                'success' => true,
                'message' => "{$count} movimiento(s) restaurado(s) correctamente.",
                'output'  => $count > 0
                    ? implode("\n", $movimientos->map(fn($m) => "Movimiento #{$m->id} (abono_id={$m->abono_id}, monto={$m->monto}) → restaurado")->toArray())
                    : "No se encontraron movimientos incorrectos. El saldo ya está correcto.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // GET /api/admin/backup
    public function backup()
    {
        try {
            $tableNames = Schema::getTableListing();
            $backup     = [];

            foreach ($tableNames as $name) {
                $backup[$name] = DB::table($name)->get()->toArray();
            }

            $filename = 'backup_' . now()->format('Y-m-d_H-i-s') . '.json';
            $content  = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return response($content, 200, [
                'Content-Type'        => 'application/json',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // POST /api/admin/restore
    public function restore(\Illuminate\Http\Request $request)
    {
        if (!$request->hasFile('backup')) {
            return response()->json(['success' => false, 'message' => 'No se recibió ningún archivo.'], 422);
        }

        $content = file_get_contents($request->file('backup')->getRealPath());
        $data    = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['success' => false, 'message' => 'El archivo no es un JSON válido.'], 422);
        }

        if (empty($data) || !is_array($data)) {
            return response()->json(['success' => false, 'message' => 'El JSON está vacío o tiene formato incorrecto.'], 422);
        }

        $skip   = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'personal_access_tokens'];
        $driver = DB::getDriverName();

        // Normalizar nombres: quitar prefijo de schema si viene del servidor (ej: "bsqbfiel_school.abonos" → "abonos")
        $normalized = [];
        foreach ($data as $key => $rows) {
            $table = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
            $normalized[$table] = $rows;
        }
        $data = $normalized;

        $tablesRestored = 0;
        $rowsInserted   = 0;
        $errors         = [];

        try {
            // 1. Truncar todas las tablas primero
            foreach ($data as $table => $rows) {
                if (in_array($table, $skip) || !Schema::hasTable($table)) continue;
                try {
                    if ($driver === 'pgsql') {
                        DB::statement("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
                    } elseif ($driver === 'mysql') {
                        DB::statement('SET FOREIGN_KEY_CHECKS=0');
                        DB::table($table)->truncate();
                        DB::statement('SET FOREIGN_KEY_CHECKS=1');
                    } else {
                        DB::table($table)->truncate();
                    }
                } catch (\Exception $e) {
                    $errors[] = "Truncate {$table}: " . $e->getMessage();
                }
            }

            // 2. Insertar datos
            foreach ($data as $table => $rows) {
                if (in_array($table, $skip) || !Schema::hasTable($table)) continue;
                if (empty($rows)) { $tablesRestored++; continue; }

                try {
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($table)->insert(array_map(fn($r) => (array) $r, $chunk));
                    }
                    $rowsInserted += count($rows);
                    $tablesRestored++;
                } catch (\Exception $e) {
                    $errors[] = "Insert {$table}: " . $e->getMessage();
                }
            }

            return response()->json([
                'success' => empty($errors),
                'message' => empty($errors)
                    ? "Restore completado: {$tablesRestored} tabla(s), {$rowsInserted} fila(s) restauradas."
                    : "Restore parcial: {$tablesRestored} tabla(s), {$rowsInserted} fila(s). Errores: " . implode(' | ', $errors),
                'output'  => empty($errors) ? null : implode("\n", $errors),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // POST /api/admin/fix-cobros-estado
    public function fixCobrosEstado()
    {
        try {
            $corregidos = \App\Models\EventoPadre::where('estado', 0)
                ->whereNotNull('monto_asignado')
                ->where('monto_asignado', '>', 0)
                ->whereColumn('monto_pagado', '>=', 'monto_asignado')
                ->get();

            $ids = $corregidos->pluck('id');
            \App\Models\EventoPadre::whereIn('id', $ids)->update(['estado' => 1]);

            return response()->json([
                'success' => true,
                'message' => "{$ids->count()} cobro(s) corregido(s).",
                'output'  => $corregidos->map(fn($ep) => "EP #{$ep->id} → estado=1")->implode("\n") ?: "Nada que corregir.",
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // POST /api/admin/fix-monto-pagado-cobros
    public function fixMontoPagadoCobros()
    {
        try {
            $corregidos = [];

            $servicio = new CobroService();

            \App\Models\EventoPadre::where('monto_pagado', '>', 0)->each(function ($ep) use (&$corregidos, $servicio) {
                $totalAbonos = (float) \App\Models\Abono::where('tipo_deuda', 'cobro')
                    ->where('deuda_id', $ep->id)
                    ->where('estado', 0)
                    ->sum('monto');
                $montoPagado = (float) $ep->monto_pagado;

                // Solo corregir cuando monto_pagado > abonos activos
                // (abono anulado que no redujo monto_pagado → CobroService lo sincroniza)
                // NO tocar cuando abonos > monto_pagado: es devolución procesada correctamente
                if ($montoPagado <= $totalAbonos) return;

                $servicio->sincronizar($ep->fresh());
                $corregidos[] = "EP #{$ep->id}: {$montoPagado} → {$totalAbonos}";
            });

            return response()->json([
                'success' => true,
                'message' => count($corregidos) . ' fila(s) corregida(s).',
                'output'  => implode("\n", $corregidos) ?: 'Nada que corregir.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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
