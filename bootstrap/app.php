<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        /*
         * LATIDO: deja constancia de que el planificador corrió. Sirve para avisar en
         * «Ajustes → Cron» si el cron del servidor no está puesto (el fallo silencioso
         * más típico al desplegar: nada falla, simplemente no entra ningún correo).
         */
        $schedule->call(fn () => \App\Models\Setting::put('cron_last_run', now()->toDateTimeString()))
            ->everyMinute()->name('cron-heartbeat')->withoutOverlapping();

        // Crons cada minuto (equivalen a flow_tick.php / campaign_tick.php)
        $schedule->command('flow:tick')->everyMinute()->withoutOverlapping();
        $schedule->command('campaign:tick')->everyMinute()->withoutOverlapping();
        // Canal correo: sondeo del buzón IMAP → tickets
        $schedule->command('email:fetch')->everyMinute()->withoutOverlapping();
        // Cierra los tickets que llevan X días resueltos (si está configurado)
        $schedule->command('tickets:autoclose')->dailyAt('03:30')->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // La API no usa cookies/sesión: solo token en cabecera.
        $middleware->alias([
            'token' => \App\Http\Middleware\TokenAuth::class,
            'admin' => \App\Http\Middleware\AdminOnly::class,

            // RBAC (spatie). Uso:  ->middleware('can:tickets.reply')
            // o bien 'permission:x|y' (cualquiera) y 'role:superadmin'.
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
