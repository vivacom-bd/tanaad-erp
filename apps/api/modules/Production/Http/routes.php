<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Production\Http\Controllers\ProductionController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/machine-wall', [ProductionController::class, 'wall']);
    Route::get('/sites/{site}/mass-balance', [ProductionController::class, 'massBalance']);
    Route::get('/sites/{site}/production/output', [ProductionController::class, 'output']);
    Route::post('/sites/{site}/production/entries', [ProductionController::class, 'entry']);
    Route::post('/sites/{site}/production/batches', [ProductionController::class, 'batch']);
    Route::post('/sites/{site}/production/shifts/close', [ProductionController::class, 'closeShift']);
    Route::post('/sites/{site}/production/waste', [ProductionController::class, 'waste']);
});
