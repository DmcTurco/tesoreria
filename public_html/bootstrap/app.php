<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Recordatorio de guardia/faena del día siguiente, todos los días a las 20:00
        $schedule->command('recordatorios:enviar')->dailyAt('20:00');

        // Recordatorio de multas/cobros pendientes de pago, martes y jueves a las 09:00
        $schedule->command('recordatorios:deudas')->twiceWeekly(2, 4, '09:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
