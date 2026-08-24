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
        // Avisos de SLA por correo (por vencer / vencido) — si están activados
        $schedule->command('sla:check')->everyFiveMinutes()->withoutOverlapping();
        // Despierta los tickets pospuestos cuya fecha venció (limpia el chip + recibimiento)
        $schedule->command('tickets:wake')->everyFiveMinutes()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * REGISTRO DE ACCIONES: observa toda la API y anota las acciones que cambian
         * datos (en terminate(), sin coste para la respuesta). Solo el superadmin lo
         * consulta. Va en el grupo `api` para verlas todas; él decide qué registra.
         */
        // Cabeceras de seguridad en TODA respuesta (SPA + API): anti-clickjacking,
        // nosniff, referrer, HSTS (solo https) y CSP.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->appendToGroup('api', \App\Http\Middleware\LogActivity::class);

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
        // Errores de validación en formato propio de la API: el frontend lee `r.error`
        // (no el `{message, errors}` por defecto de Laravel). Así se puede validar con
        // reglas (`$request->validate([...])`) sin romper cómo la app muestra los errores.
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'ok'     => false,
                    'error'  => $e->validator->errors()->first(),   // el primero, para el toast
                    'errors' => $e->errors(),                        // por si algún día se usan campo a campo
                ], 422);
            }
        });
    })->create();
