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

    Route::post('/logout',          [AuthController::class, 'logout']);
    Route::get('/me',               [AuthController::class, 'me']);
    Route::put('/cambiar-password', [AuthController::class, 'cambiarPassword']);

    // ── Lectura compartida (todos los roles) ─────────────────────────────────
    // Definidas UNA SOLA VEZ aquí — sin middleware de rol
    Route::get('/eventos',                     [EventoController::class,    'index']);
    Route::get('/eventos/{evento}',            [EventoController::class,    'show']);
    Route::get('/eventos/{evento}/padres',     [EventoController::class,    'padres']);
    Route::get('/movimientosGlobal',                 [MovimientoController::class, 'index']);
    Route::get('/multas',                      [MultaController::class,     'index']);
    Route::get('/padres',                      [PadreController::class,     'index']);
    Route::get('/padres/{padre}',              [PadreController::class,     'show']);
    Route::get('/padres/{padre}/qr',           [PadreController::class,     'qr']);
    Route::get('/pagos',                       [PagoController::class,      'index']);
    Route::get('/presupuestos',                [PresupuestoController::class, 'index']);
    Route::get('/reportes/dashboard',          [ReporteController::class,   'dashboard']);
    Route::get('/reportes/deudores',           [ReporteController::class,   'deudores']);
    Route::get('/reportes/movimientos-por-mes', [ReporteController::class,   'movimientosPorMes']);

    // ── Solo padre (2) ───────────────────────────────────────────────────────
    Route::middleware('role:2')->group(function () {
        Route::get('/mi-qr', function (\Illuminate\Http\Request $request) {
            $padre = $request->user()->padre;
            if (!$padre) return response()->json(['message' => 'Sin perfil de padre'], 404);
            return response()->json(['codigo' => $padre->codigo, 'qr_data' => $padre->qrData()]);
        });

        Route::get('/mi-estado', function (\Illuminate\Http\Request $request) {
            $padre = $request->user()->padre;
            if (!$padre) return response()->json(['message' => 'Sin perfil de padre'], 404);
            return response()->json([
                'padre'       => $padre,
                'saldo_deuda' => $padre->saldoDeuda(),
                'multas'      => $padre->multas()->with('evento')->orderByDesc('fecha_generada')->get(),
                'pagos'       => $padre->pagos()->with('conceptoPago')->orderByDesc('fecha')->get(),
                'eventos'     => $padre->eventoPadres()->with('evento')->orderByDesc('created_at')->get(),
            ]);
        });
    });

    // ── Tesorero + Profesora (0,1) ───────────────────────────────────────────
    Route::middleware('role:0,1')->group(function () {
        Route::post('/eventos/{evento}/asistencia', [EventoController::class, 'registrarAsistencia']);
        Route::post('/eventos/{evento}/cerrar',     [EventoController::class, 'cerrar']);
    });

    // ── Solo tesorero (0) ────────────────────────────────────────────────────
    Route::middleware('role:0')->group(function () {

        // Padres
        Route::post('/padres',                        [PadreController::class, 'store']);
        Route::put('/padres/{padre}',                [PadreController::class, 'update']);
        Route::delete('/padres/{padre}',                [PadreController::class, 'destroy']);
        Route::put('/padres/{padre}/reset-password', [PadreController::class, 'resetPassword']);

        // Pagos
        Route::get('/pagos/{pago}',        [PagoController::class, 'show']);
        Route::post('/pagos',               [PagoController::class, 'store']);
        Route::put('/pagos/{pago}/anular', [PagoController::class, 'anular']);

        // Movimientos
        Route::get('/movimientos/{movimiento}', [MovimientoController::class, 'show']);
        Route::post('/movimientos',              [MovimientoController::class, 'store']);
        Route::put('/movimientos/{movimiento}', [MovimientoController::class, 'update']);
        Route::delete('/movimientos/{movimiento}', [MovimientoController::class, 'destroy']);

        // Multas
        Route::get('/multas/{multa}',          [MultaController::class, 'show']);
        Route::post('/multas/{multa}/pagar',    [MultaController::class, 'pagar']);
        Route::post('/multas/{multa}/exonerar', [MultaController::class, 'exonerar']);
        Route::post('/multas/{multa}/anular',   [MultaController::class, 'anular']);

        // Presupuesto
        Route::post('/presupuestos',               [PresupuestoController::class, 'store']);
        Route::put('/presupuestos/{presupuesto}', [PresupuestoController::class, 'update']);
        Route::delete('/presupuestos/{presupuesto}', [PresupuestoController::class, 'destroy']);

        // Eventos
        Route::post('/eventos',                         [EventoController::class, 'store']);
        Route::put('/eventos/{evento}',                [EventoController::class, 'update']);
        Route::post('/eventos/{evento}/exonerar-padre', [EventoController::class, 'exonerarPadre']);
    });
});
