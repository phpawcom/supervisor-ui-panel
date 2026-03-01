<?php

use App\Http\Controllers\CPanel\DashboardController;
use App\Http\Controllers\CPanel\WorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| cPanel Plugin Routes
|--------------------------------------------------------------------------
| All routes are protected by CpanelAuthMiddleware + EnsureAccountIsolation.
| Rate limiting is applied to sensitive write actions.
*/

Route::prefix('cpanel')->name('cpanel.')->middleware([
    'cpanel.auth',
    'account.isolation',
    'throttle:120,1',
])->group(function () {

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/status/poll', [DashboardController::class, 'pollStatus'])->name('status.poll');
    Route::get('/status/lve', [DashboardController::class, 'lveStatus'])->name('status.lve');
    Route::post('/apps/scan', [DashboardController::class, 'scanApps'])
        ->middleware('throttle:5,1')
        ->name('apps.scan');

    // -------------------------------------------------------------------------
    // Workers
    // -------------------------------------------------------------------------
    Route::get('/workers', [WorkerController::class, 'index'])->name('workers.index');
    Route::get('/workers/create', [WorkerController::class, 'create'])->name('workers.create');

    Route::post('/workers', [WorkerController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('workers.store');

    Route::get('/workers/{worker}', [WorkerController::class, 'show'])->name('workers.show');

    Route::delete('/workers/{worker}', [WorkerController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('workers.destroy');

    // Process control (rate limited per user)
    Route::post('/workers/{worker}/restart', [WorkerController::class, 'restart'])
        ->middleware('throttle:20,1')
        ->name('workers.restart');

    Route::post('/workers/{worker}/stop', [WorkerController::class, 'stop'])
        ->middleware('throttle:20,1')
        ->name('workers.stop');

    Route::post('/workers/{worker}/start', [WorkerController::class, 'start'])
        ->middleware('throttle:20,1')
        ->name('workers.start');

    // Log tail
    Route::get('/workers/{worker}/logs', [WorkerController::class, 'logs'])
        ->middleware('throttle:30,1')
        ->name('workers.logs');
});
