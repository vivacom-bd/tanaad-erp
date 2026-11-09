<?php

declare(strict_types=1);

namespace Tanaad\Shared;

use Illuminate\Support\ServiceProvider;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\RebuildCommand;
use Tanaad\Shared\Ledger\Registry;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class);
        $this->app->singleton(CommandBus::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildCommand::class]);
        }
    }
}
