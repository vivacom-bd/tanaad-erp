<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Purchases\Http\Controllers\PurchasesController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/purchases', [PurchasesController::class, 'index']);
    Route::post('/sites/{site}/purchases', [PurchasesController::class, 'record']);
    Route::post('/sites/{site}/purchases/{purchase}/change', [PurchasesController::class, 'change']);
    Route::post('/sites/{site}/purchases/{purchase}/receive', [PurchasesController::class, 'receive']);
});
