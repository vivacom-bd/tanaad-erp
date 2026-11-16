<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Dispatch\Http\Controllers\DispatchController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/dispatches', [DispatchController::class, 'index']);
    Route::get('/sites/{site}/dispatches/{dispatch}', [DispatchController::class, 'show']);
    Route::post('/sites/{site}/dispatches', [DispatchController::class, 'start']);
    Route::post('/sites/{site}/dispatches/{dispatch}/scan', [DispatchController::class, 'scan']);
    Route::post('/sites/{site}/dispatches/{dispatch}/unscan', [DispatchController::class, 'unscan']);
    Route::post('/sites/{site}/dispatches/{dispatch}/gate-weight', [DispatchController::class, 'weigh']);
    Route::post('/sites/{site}/dispatches/{dispatch}/override', [DispatchController::class, 'override']);
    Route::post('/sites/{site}/dispatches/{dispatch}/delivery', [DispatchController::class, 'deliver']);
});
