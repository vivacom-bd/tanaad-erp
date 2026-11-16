<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Orders\Http\Controllers\OrdersController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/clients', [OrdersController::class, 'clients']);
    Route::post('/sites/{site}/clients', [OrdersController::class, 'registerClient']);
    Route::put('/sites/{site}/clients/{client}', [OrdersController::class, 'updateClient']);
    Route::get('/sites/{site}/clients/{client}/statement', [OrdersController::class, 'statement']);
    Route::get('/sites/{site}/receivables', [OrdersController::class, 'receivables']);
    Route::get('/sites/{site}/orders', [OrdersController::class, 'orders']);
    Route::post('/sites/{site}/orders', [OrdersController::class, 'create']);
    Route::get('/sites/{site}/orders/{order}', [OrdersController::class, 'show']);
    Route::get('/sites/{site}/orders/{order}/dispatch-snapshot', [OrdersController::class, 'dispatchSnapshot']);
    Route::post('/sites/{site}/orders/{order}/confirm-cash', [OrdersController::class, 'confirmCash']);
    Route::post('/sites/{site}/orders/{order}/approve', [OrdersController::class, 'approve']);
    Route::post('/sites/{site}/orders/{order}/hold', [OrdersController::class, 'hold']);
    Route::post('/sites/{site}/orders/{order}/invoice', [OrdersController::class, 'invoice']);
    Route::get('/sites/{site}/invoices', [OrdersController::class, 'invoices']);
    Route::post('/sites/{site}/invoices/{invoice}/payments', [OrdersController::class, 'pay']);
    Route::get('/sites/{site}/payments', [OrdersController::class, 'payments']);
});
