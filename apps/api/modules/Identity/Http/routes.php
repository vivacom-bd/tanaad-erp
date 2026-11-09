<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Identity\Http\Controllers\AuthController;
use Tanaad\Identity\Http\Controllers\DeviceController;
use Tanaad\Identity\Http\Controllers\UserRoleController;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/pin-login', [AuthController::class, 'pinLogin'])->middleware('throttle:pin-login');
    Route::post('/auth/pin', [AuthController::class, 'pin'])->middleware(['auth:sanctum', 'abilities:device', 'throttle:30,1']);

    Route::middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
        Route::delete('/auth/session', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/sites/{site}/devices', [DeviceController::class, 'store'])->middleware('site:group_admin');
        Route::post('/users/{user}/roles', [UserRoleController::class, 'store']);
    });
});
