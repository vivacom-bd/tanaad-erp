<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Group\Http\Controllers\AlertController;
use Tanaad\Group\Http\Controllers\BriefController;
use Tanaad\Group\Http\Controllers\CockpitController;
use Tanaad\Group\Http\Controllers\ExceptionController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
    Route::get('/exceptions', [ExceptionController::class, 'all']);
    Route::get('/alerts', AlertController::class);
    Route::get('/group/cockpit', [CockpitController::class, 'cockpit']);
    Route::get('/group/approvals', [CockpitController::class, 'approvals']);
    Route::get('/group/audit', [CockpitController::class, 'audit']);
    Route::get('/briefs', [BriefController::class, 'index']);
    Route::post('/briefs', [BriefController::class, 'send']);
});

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user', 'site'])->group(function (): void {
    Route::get('/sites/{site}/exceptions', [ExceptionController::class, 'site']);
    Route::post('/sites/{site}/exceptions', [ExceptionController::class, 'raise']);
    Route::post('/sites/{site}/exceptions/{exception}/decision', [ExceptionController::class, 'decide']);
});
