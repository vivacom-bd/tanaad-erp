<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// routes/api.php only requires each module's routes file; modules prefix with v1.
Route::prefix('v1')->group(function (): void {
    Route::get('/ping', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()]));
});

require __DIR__.'/../modules/Identity/Http/routes.php';
require __DIR__.'/../modules/Config/Http/routes.php';
require __DIR__.'/../modules/Sync/Http/routes.php';
require __DIR__.'/../modules/Media/Http/routes.php';
require __DIR__.'/../modules/Messaging/Http/routes.php';
require __DIR__.'/../modules/Documents/Http/routes.php';
require __DIR__.'/../modules/Cash/Http/routes.php';
require __DIR__.'/../modules/Production/Http/routes.php';
require __DIR__.'/../modules/Labels/Http/routes.php';
require __DIR__.'/../modules/People/Http/routes.php';
require __DIR__.'/../modules/Group/Http/routes.php';
require __DIR__.'/../modules/Recycling/Http/routes.php';
require __DIR__.'/../modules/Dispatch/Http/routes.php';
require __DIR__.'/../modules/Orders/Http/routes.php';
require __DIR__.'/../modules/Spares/Http/routes.php';
require __DIR__.'/../modules/Purchases/Http/routes.php';
require __DIR__.'/../modules/Costing/Http/routes.php';
require __DIR__.'/../modules/Stock/Http/routes.php';
