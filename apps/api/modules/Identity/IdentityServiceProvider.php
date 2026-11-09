<?php

declare(strict_types=1);

namespace Tanaad\Identity;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Tanaad\Identity\Authorization\IdentityAuthorizer;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Commands\OpenDeviceSession;
use Tanaad\Identity\Commands\RegisterDevice;
use Tanaad\Identity\Handlers\AssignRoleHandler;
use Tanaad\Identity\Handlers\OpenDeviceSessionHandler;
use Tanaad\Identity\Handlers\RegisterDeviceHandler;
use Tanaad\Identity\Http\EnsureSiteAccess;
use Tanaad\Identity\Projectors\IdentityProjector;
use Tanaad\Shared\Ledger\Registry;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('site', EnsureSiteAccess::class);

        RateLimiter::for('pin-login', fn (Request $r): Limit => Limit::perMinute(5)
            ->by(strtolower(trim((string) $r->input('username'))).'|'.$r->ip()));

        $registry
            ->command(RegisterDevice::class, RegisterDeviceHandler::class, IdentityAuthorizer::class)
            ->command(AssignRole::class, AssignRoleHandler::class, IdentityAuthorizer::class)
            ->command(OpenDeviceSession::class, OpenDeviceSessionHandler::class, IdentityAuthorizer::class)
            ->projectors(IdentityProjector::class);
    }
}
