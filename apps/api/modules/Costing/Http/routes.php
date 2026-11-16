<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Costing\Http\Controllers\CostingController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
    Route::get('/costing/pnl', [CostingController::class, 'group']);
});

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/costing/pnl', [CostingController::class, 'pnl']);
    Route::get('/sites/{site}/costing/unit-cost', [CostingController::class, 'unitCost']);
    Route::post('/sites/{site}/costing/periods/close', [CostingController::class, 'close']);
    Route::post('/sites/{site}/costing/periods/reopen', [CostingController::class, 'reopen']);
    Route::post('/sites/{site}/costing/mapping', [CostingController::class, 'mapping']);
});
