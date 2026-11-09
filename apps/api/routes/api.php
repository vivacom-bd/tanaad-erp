<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Each module registers its own routes from its service provider under /api/v1.
Route::prefix('v1')->group(function (): void {
    Route::get('/ping', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()]));
});
