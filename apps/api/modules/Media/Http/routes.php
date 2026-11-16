<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Media\Http\Controllers\MediaController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
    Route::post('/media', [MediaController::class, 'store']);
    Route::get('/media/{hash}', [MediaController::class, 'show'])->where('hash', '[0-9a-f]{64}');
});
