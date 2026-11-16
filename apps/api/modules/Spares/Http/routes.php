<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Spares\Http\Controllers\SparesController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/spares', [SparesController::class, 'store']);
    Route::get('/sites/{site}/breakdowns', [SparesController::class, 'breakdowns']);
    Route::get('/sites/{site}/downtime-cost', [SparesController::class, 'downtimeCost']);
    Route::post('/sites/{site}/parts', [SparesController::class, 'savePart']);
    Route::post('/sites/{site}/parts/{part}/receive', [SparesController::class, 'receive']);
    Route::post('/sites/{site}/parts/{part}/issue', [SparesController::class, 'issue']);
    Route::post('/sites/{site}/breakdowns', [SparesController::class, 'report']);
    Route::post('/sites/{site}/breakdowns/{breakdown}/resolve', [SparesController::class, 'resolve']);
});
