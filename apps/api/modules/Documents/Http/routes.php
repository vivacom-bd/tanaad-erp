<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tanaad\Documents\Http\Controllers\DocumentController;

Route::prefix('v1')->middleware(['auth:sanctum', 'abilities:user'])->group(function (): void {
    Route::post('/documents/{kind}/{id}/render', [DocumentController::class, 'render']);
});
