<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Recycling\Http\Controllers\RecyclingController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/collector-deliveries', [RecyclingController::class, 'deliveries']);
    Route::post('/sites/{site}/collector-deliveries', [RecyclingController::class, 'record']);
    Route::post('/sites/{site}/collector-deliveries/{delivery}/pay', [RecyclingController::class, 'pay']);
    Route::get('/sites/{site}/collector-ledger', [RecyclingController::class, 'ledger']);
});
