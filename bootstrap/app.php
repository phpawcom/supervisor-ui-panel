<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health:   '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/cpanel.php'));

            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/whm.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'cpanel.auth'       => \App\Http\Middleware\CpanelAuthMiddleware::class,
            'whm.auth'          => \App\Http\Middleware\WhmAuthMiddleware::class,
            'account.isolation' => \App\Http\Middleware\EnsureAccountIsolation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
