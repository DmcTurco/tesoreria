<?php

use App\Http\Controllers\Api\AbonoController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventoController;
use App\Http\Controllers\Api\EventoPadreController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\MultaController;
use App\Http\Controllers\Api\PadreController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PresupuestoController;
use App\Http\Controllers\Api\ReporteController;
use Illuminate\Support\Facades\Route;

// ── Públicas ──────────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);

// ── Autenticadas ──────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout',          [AuthController::class, 'logout']);
    Route::get('/me',               [AuthController::class, 'me']);
    Route::put('/cambiar-password', [AuthController::class, 'cambiarPassword']);

    // ── Lectura compartida (todos los roles) ──────────────────────────────────
    Route::get('/eventos',                      [EventoController::class,     'index']);
    Route::get('/eventos/{evento}',             [EventoController::class,     'show']);
    Route::get('/eventos/{evento}/padres',      [EventoController::class,     'padres']);
    Route::get('/eventos/{evento}/fechas',      [EventoController::class,     'fechas']);
    Route::get('/movimientos',                  [MovimientoController::class, 'index']);
    Route::get('/multas',                       [MultaController::class,      'index']);
    Route::get('/padres',                       [PadreController::class,      'index']);
    Route::get('/padres/con-deuda',             [PagoController::class,       'padresConDeuda']);
    Route::get('/padres/{padre}',               [PadreController::class,      'show']);
    Route::get('/padres/{padre}/qr',            [PadreController::class,      'qr']);
    Route::get('/pagos',                        [PagoController::class,       'index']);
    Route::get('/presupuestos',                 [PresupuestoController::class, 'index']);
    Route::get('/reportes/dashboard',           [ReporteController::class,    'dashboard']);
    Route::get('/reportes/deudores',            [ReporteController::class,    'deudores']);
    Route::get('/reportes/movimientos-por-mes', [ReporteController::class,    'movimientosPorMes']);
    Route::get('/eventos/{evento}/precio-historial', [EventoController::class, 'precioHistorial']);
    Route::get('/eventos/{evento}/gastos',           [EventoController::class, 'gastos']);

    // ── Solo padre (2) ────────────────────────────────────────────────────────
    Route::middleware('role:2')->group(function () {
        Route::get('/mi-qr',     [PadreController::class, 'miQr']);
        Route::get('/mi-estado', [PadreController::class, 'miEstado']);
    });

    // ── Tesorero + Profesora (0,1) ────────────────────────────────────────────
    Route::middleware('role:0,1')->group(function () {
        Route::post('/eventos/{evento}/asistencia', [EventoController::class, 'registrarAsistencia']);
        Route::post('/eventos/{evento}/cerrar',     [EventoController::class,      'cerrar']);
    });

    // ── Solo tesorero (0) ─────────────────────────────────────────────────────
    Route::middleware('role:0')->group(function () {

        // Admin / DB
        Route::post('/admin/migrate',       [AdminController::class, 'migrate']);
        Route::post('/admin/migrate-fresh', [AdminController::class, 'migrateFresh']);


        // Estado padre (para modal de pago)
        Route::get('/mi-estado-tesorero', [PadreController::class, 'miEstadoTesorero']);

        // Evento padres
        Route::put('/evento-padres/{eventoPadre}/pagar', [EventoPadreController::class, 'pagar']);

        // Padres
        Route::post('/padres/importar',              [PadreController::class, 'importar']);
        Route::post('/padres',                       [PadreController::class, 'store']);
        Route::put('/padres/{padre}',                [PadreController::class, 'update']);
        Route::delete('/padres/{padre}',             [PadreController::class, 'destroy']);
        Route::put('/padres/{padre}/reset-password', [PadreController::class, 'resetPassword']);

        // Multas
        Route::get('/multas/{multa}',           [MultaController::class, 'show']);
        Route::post('/multas/{multa}/pagar',    [MultaController::class, 'pagar']);
        Route::post('/multas/{multa}/exonerar', [MultaController::class, 'exonerar']);
        Route::post('/multas/{multa}/anular',   [MultaController::class, 'anular']);

        // Abonos  area flujo tecnico de cobro
        Route::get('/abonos',              [AbonoController::class, 'index']);
        Route::post('/abonos',             [AbonoController::class, 'store']);
        Route::post('/abonos/{id}/anular', [AbonoController::class, 'anular']);

        // Movimientos
        Route::get('/movimientos/{movimiento}',    [MovimientoController::class, 'show']);
        Route::post('/movimientos',                [MovimientoController::class, 'store']);
        Route::put('/movimientos/{movimiento}',    [MovimientoController::class, 'update']);
        Route::delete('/movimientos/{movimiento}', [MovimientoController::class, 'destroy']);

        // Presupuestos
        Route::post('/presupuestos',                [PresupuestoController::class, 'store']);
        Route::put('/presupuestos/{presupuesto}',   [PresupuestoController::class, 'update']);
        Route::delete('/presupuestos/{presupuesto}', [PresupuestoController::class, 'destroy']);

        // Eventos
        Route::get('eventos/{evento}/ajustes',          [EventoController::class, 'ajustes']);
        Route::get('/eventos/{evento}/movimientos', [EventoController::class, 'movimientos']);
        Route::post('eventos/{evento}/resolver-ajuste', [EventoController::class, 'resolverAjuste']);

        Route::post('/eventos',                              [EventoController::class, 'store']);
        Route::put('/eventos/{evento}',                      [EventoController::class, 'update']);
        Route::post('/eventos/{evento}/exonerar-padre',      [EventoController::class, 'exonerarPadre']);
        Route::post('/eventos/{evento}/agregar-padre',       [EventoController::class, 'agregarPadre']);
        Route::put('/eventos/{evento}/quitar-padre/{padre}', [EventoController::class, 'quitarPadre']);
        Route::delete('/eventos/{evento}/quitar-padre/{padre}', [EventoController::class, 'eliminarPadre']);
    });
});
