<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Config\Http\Controllers\ConfigController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
    Route::middleware('site')->group(function (): void {
        Route::get('/sites/{site}/config', [ConfigController::class, 'show']);
        Route::put('/sites/{site}/settings', [ConfigController::class, 'settings']);
        Route::post('/sites/{site}/refs', [ConfigController::class, 'ref']);
        Route::post('/sites/{site}/products', [ConfigController::class, 'product']);
        Route::post('/sites/{site}/machines', [ConfigController::class, 'machine']);
        Route::post('/sites/{site}/benchmarks', [ConfigController::class, 'benchmark']);
        Route::post('/sites/{site}/zones', [ConfigController::class, 'zone']);
        Route::post('/sites/{site}/company-profile', [ConfigController::class, 'companyProfile']);
        Route::post('/sites/{site}/number-series', [ConfigController::class, 'numberSeries']);
    });
    Route::post('/fx', [ConfigController::class, 'fx']);
});
