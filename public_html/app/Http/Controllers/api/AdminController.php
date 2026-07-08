<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

            // 3. Sincronizar secuencias (Postgres): tras insertar filas con "id" explícito,
            //    la secuencia queda desfasada y la próxima inserción sin id choca con
            //    "duplicate key value violates unique constraint ..._pkey".
            if ($driver === 'pgsql') {
                foreach ($data as $table => $rows) {
                    if (in_array($table, $skip) || !Schema::hasTable($table)) continue;
                    if (empty($rows) || !Schema::hasColumn($table, 'id')) continue;

                    try {
                        DB::statement("
                            SELECT setval(
                                pg_get_serial_sequence('\"{$table}\"', 'id'),
                                COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1)
                            )
                        ");
                    } catch (\Exception $e) {
                        $errors[] = "Sync sequence {$table}: " . $e->getMessage();
                    }
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

    // POST /api/admin/enviar-recordatorios
    public function enviarRecordatorios()
    {
        try {
            Artisan::call('recordatorios:enviar');
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'Comando de recordatorios ejecutado correctamente',
                'output'  => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // POST /api/admin/enviar-recordatorios-deudas
    public function enviarRecordatoriosDeudas()
    {
        try {
            Artisan::call('recordatorios:deudas');
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'Comando de recordatorios de deuda ejecutado correctamente',
                'output'  => $output,
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
