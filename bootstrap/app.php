<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('order-sessions:expire')->everyMinute()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // Enable CORS globally so preflight and error responses include headers
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

        // Your custom middleware aliases
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'telegram.mini.app' => \App\Http\Middleware\VerifyTelegramInitData::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
