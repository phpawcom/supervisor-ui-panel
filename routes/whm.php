<?php

use App\Http\Controllers\WHM\PackageController;
use App\Http\Controllers\WHM\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WHM Admin Plugin Routes
|--------------------------------------------------------------------------
| All routes are protected by WhmAuthMiddleware.
*/

Route::prefix('whm')->name('whm.')->middleware([
    'whm.auth',
    'throttle:200,1',
])->group(function () {

    // -------------------------------------------------------------------------
    // Global Settings & Dashboard
    // -------------------------------------------------------------------------
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/global', [SettingsController::class, 'updateGlobalSettings'])->name('settings.update');

    Route::get('/workers/all', [SettingsController::class, 'allWorkers'])->name('workers.all');

    // Port management
    Route::get('/ports', [SettingsController::class, 'portUsage'])->name('ports.index');
    Route::delete('/ports/{port}', [SettingsController::class, 'releasePort'])
        ->where('port', '[0-9]+')
        ->name('ports.release');

    // -------------------------------------------------------------------------
    // Package Limits
    // -------------------------------------------------------------------------
    Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
    Route::get('/packages/{packageName}/edit', [PackageController::class, 'edit'])->name('packages.edit');
    Route::get('/packages/{packageName}', [PackageController::class, 'show'])->name('packages.show');
    Route::put('/packages/{packageName}', [PackageController::class, 'upsert'])->name('packages.upsert');
    Route::post('/packages/{packageName}', [PackageController::class, 'upsert'])->name('packages.upsert.post');
    Route::delete('/packages/{packageName}', [PackageController::class, 'destroy'])->name('packages.destroy');
});
