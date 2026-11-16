<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Labels\Http\Controllers\LabelsController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
    // The label code carries its site; access is checked against the label's own site.
    Route::get('/labels/{code}/history', [LabelsController::class, 'history']);
    Route::middleware('site')->group(function (): void {
        Route::get('/sites/{site}/labels', [LabelsController::class, 'index']);
        Route::get('/sites/{site}/label-stock', [LabelsController::class, 'stock']);
        Route::post('/sites/{site}/labels', [LabelsController::class, 'create']);
        Route::post('/sites/{site}/labels/{label}/consume', [LabelsController::class, 'consume']);
    });
});
