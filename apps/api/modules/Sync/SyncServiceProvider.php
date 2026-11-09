<?php

declare(strict_types=1);

namespace Tanaad\Sync;

use Illuminate\Support\ServiceProvider;

final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Snapshots::class);
    }

    public function boot(Snapshots $snapshots): void
    {
        $snapshots->register('devices', 'devices');
    }
}
