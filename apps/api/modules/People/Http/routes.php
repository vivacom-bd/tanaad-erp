<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\People\Http\Controllers\PeopleController;
use Tanaad\People\Http\Controllers\SettlementController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::post('/sites/{site}/people', [PeopleController::class, 'register']);
    Route::post('/sites/{site}/people/{person}/engagements', [PeopleController::class, 'start']);
    Route::post('/sites/{site}/people/{person}/archive', [PeopleController::class, 'archive']);
    Route::post('/sites/{site}/people/{person}/reactivate', [PeopleController::class, 'reactivate']);
    Route::post('/sites/{site}/engagements/{engagement}/renew', [PeopleController::class, 'renew']);
    Route::post('/sites/{site}/engagements/{engagement}/end', [PeopleController::class, 'end']);
    Route::post('/sites/{site}/attendance', [PeopleController::class, 'attendance']);
    Route::get('/sites/{site}/settlement-runs', [SettlementController::class, 'index']);
    Route::get('/sites/{site}/advances', [SettlementController::class, 'advances']);
    Route::post('/sites/{site}/settlement-runs', [SettlementController::class, 'create']);
    Route::get('/sites/{site}/settlement-runs/{run}', [SettlementController::class, 'show']);
    Route::post('/sites/{site}/settlement-runs/{run}/approve', [SettlementController::class, 'approve']);
    Route::post('/sites/{site}/settlement-runs/{run}/cancel', [SettlementController::class, 'cancel']);
    Route::post('/sites/{site}/settlement-runs/{run}/payouts', [SettlementController::class, 'payout']);
    Route::post('/sites/{site}/advances', [SettlementController::class, 'advance']);
    Route::get('/sites/{site}/headcount', [PeopleController::class, 'headcount']);
});
