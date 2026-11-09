<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Sync\Http\Controllers\IngestController;
use Tanaad\Sync\Http\Controllers\SnapshotController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::post('/sites/{site}/events', IngestController::class);
    Route::get('/sites/{site}/snapshots/{projection}', SnapshotController::class);
});
