<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Messaging\Http\Controllers\MessageController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
    Route::get('/messages', [MessageController::class, 'index']);
    Route::post('/messages/recipients', [MessageController::class, 'saveRecipient']);
});
