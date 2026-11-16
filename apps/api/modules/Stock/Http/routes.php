<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Stock\Http\Controllers\StockController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/stock', [StockController::class, 'onHand']);
    Route::get('/sites/{site}/stock/movements', [StockController::class, 'movements']);
    Route::get('/sites/{site}/stock/count-variance', [StockController::class, 'variance']);
    Route::get('/sites/{site}/stock/transfers', [StockController::class, 'transfers']);
    Route::post('/sites/{site}/stock/receipts', [StockController::class, 'receive']);
    Route::post('/sites/{site}/stock/moves', [StockController::class, 'move']);
    Route::post('/sites/{site}/stock/labels/{label}/scrap', [StockController::class, 'scrap']);
    Route::post('/sites/{site}/stock/transfers', [StockController::class, 'send']);
    Route::post('/sites/{site}/stock/transfers/{transfer}/receive', [StockController::class, 'receiveTransfer']);
    Route::post('/sites/{site}/stock/counts', [StockController::class, 'count']);
    Route::post('/sites/{site}/stock/counts/reopen', [StockController::class, 'reopen']);
});
