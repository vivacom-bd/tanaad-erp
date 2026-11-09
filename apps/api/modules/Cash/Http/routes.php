<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Cash\Http\Controllers\CashController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/cash/days/{day}', [CashController::class, 'day']);
    Route::get('/sites/{site}/cash/months/{month}', [CashController::class, 'month']);
    Route::post('/sites/{site}/cash/entries', [CashController::class, 'record']);
    Route::post('/sites/{site}/cash/entries/{entry}/corrections', [CashController::class, 'correct']);
    Route::post('/sites/{site}/cash/days/{day}/close', [CashController::class, 'close']);
    Route::post('/sites/{site}/cash/days/{day}/opening', [CashController::class, 'opening']);
    Route::post('/sites/{site}/cash/days/{day}/reopen', [CashController::class, 'reopen']);
});
