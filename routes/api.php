<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PadreController;
use App\Http\Controllers\Api\EventoController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\MultaController;
use App\Http\Controllers\Api\PresupuestoController;
use App\Http\Controllers\Api\ReporteController;

// ── Públicas ──────────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);

// ── Autenticadas ──────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout',           [AuthController::class, 'logout']);
    Route::get('/me',                [AuthController::class, 'me']);
    Route::put('/cambiar-password',  [AuthController::class, 'cambiarPassword']);

    // ── Solo tesorero ─────────────────────────────────────────────────────────
    Route::middleware('role:0')->group(function () {

        // Padres
        Route::get('/padres',                       [PadreController::class, 'index']);
        Route::post('/padres',                      [PadreController::class, 'store']);
        Route::put('/padres/{padre}',               [PadreController::class, 'update']);
        Route::delete('/padres/{padre}',            [PadreController::class, 'destroy']);
        Route::put('/padres/{padre}/reset-password', [PadreController::class, 'resetPassword']);

        // Pagos
        Route::get('/pagos',                        [PagoController::class, 'index']);
        Route::get('/pagos/{pago}',                 [PagoController::class, 'show']);
        Route::post('/pagos',                       [PagoController::class, 'store']);
        Route::put('/pagos/{pago}/anular',          [PagoController::class, 'anular']);

        // Movimientos
        Route::get('/movimientos',                  [MovimientoController::class, 'index']);
        Route::get('/movimientos/{movimiento}',     [MovimientoController::class, 'show']);
        Route::post('/movimientos',                 [MovimientoController::class, 'store']);
        Route::put('/movimientos/{movimiento}',     [MovimientoController::class, 'update']);
        Route::delete('/movimientos/{movimiento}',  [MovimientoController::class, 'destroy']);

        // Multas
        Route::get('/multas',                       [MultaController::class, 'index']);
        Route::get('/multas/{multa}',               [MultaController::class, 'show']);
        Route::post('/multas/{multa}/pagar',        [MultaController::class, 'pagar']);
        Route::post('/multas/{multa}/exonerar',     [MultaController::class, 'exonerar']);
        Route::post('/multas/{multa}/anular',       [MultaController::class, 'anular']);

        // Presupuesto
        Route::get('/presupuestos',                 [PresupuestoController::class, 'index']);
        Route::post('/presupuestos',                [PresupuestoController::class, 'store']);
        Route::put('/presupuestos/{presupuesto}',   [PresupuestoController::class, 'update']);
        Route::delete('/presupuestos/{presupuesto}', [PresupuestoController::class, 'destroy']);

        // Eventos (gestión completa)
        Route::post('/eventos',                                 [EventoController::class, 'store']);
        Route::put('/eventos/{evento}',                         [EventoController::class, 'update']);
        Route::post('/eventos/{evento}/exonerar-padre',         [EventoController::class, 'exonerarPadre']);

        // Reportes
        Route::get('/reportes/dashboard',              [ReporteController::class, 'dashboard']);
        Route::get('/reportes/deudores',               [ReporteController::class, 'deudores']);
        Route::get('/reportes/movimientos-por-mes',    [ReporteController::class, 'movimientosPorMes']);
    });

    // ── Tesorero y profesora ──────────────────────────────────────────────────
    Route::middleware('role:0,1')->group(function () {

        // Eventos (consulta y operaciones de asistencia)
        Route::get('/eventos',                              [EventoController::class, 'index']);
        Route::get('/eventos/{evento}',                     [EventoController::class, 'show']);
        Route::get('/eventos/{evento}/padres',              [EventoController::class, 'padres']);
        Route::post('/eventos/{evento}/asistencia',         [EventoController::class, 'registrarAsistencia']);
        Route::post('/eventos/{evento}/cerrar',             [EventoController::class, 'cerrar']);

        // Ver QR de cualquier padre (para escaneo en eventos)
        Route::get('/padres/{padre}',                       [PadreController::class, 'show']);
        Route::get('/padres/{padre}/qr',                    [PadreController::class, 'qr']);
    });

    // ── Padre (solo lectura de su propia info) ────────────────────────────────
    Route::middleware('role:2')->group(function () {

        // Su propio QR
        Route::get('/mi-qr', function (\Illuminate\Http\Request $request) {
            $padre = $request->user()->padre;
            if (!$padre) {
                return response()->json(['message' => 'No tiene perfil de padre asociado'], 404);
            }
            return response()->json([
                'codigo'  => $padre->codigo,
                'qr_data' => $padre->qrData(),
            ]);
        });

        // Su estado: deuda, multas, pagos
        Route::get('/mi-estado', function (\Illuminate\Http\Request $request) {
            $padre = $request->user()->padre;
            if (!$padre) {
                return response()->json(['message' => 'No tiene perfil de padre asociado'], 404);
            }
            return response()->json([
                'padre'          => $padre,
                'saldo_deuda'    => $padre->saldoDeuda(),
                'multas'         => $padre->multas()->with('evento')->orderByDesc('fecha_generada')->get(),
                'pagos'          => $padre->pagos()->with('conceptoPago')->orderByDesc('fecha')->get(),
                'eventos'        => $padre->eventoPadres()->with('evento')->orderByDesc('created_at')->get(),
            ]);
        });

        // Eventos activos (transparencia — puede verlos todos)
        Route::get('/eventos',          [EventoController::class, 'index']);
        Route::get('/eventos/{evento}', [EventoController::class, 'show']);

        // Movimientos (transparencia — solo lectura)
        Route::get('/movimientos',      [MovimientoController::class, 'index']);
    });
});
