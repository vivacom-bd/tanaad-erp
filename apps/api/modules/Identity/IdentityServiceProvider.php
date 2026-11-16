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
    private static function principal(Request $r): string
    {
        $user = $r->user('sanctum');

        return $user === null ? 'ip|'.$r->ip() : 'user|'.(string) $user->getAuthIdentifier();
    }

    private static function deviceKey(Request $r): string
    {
        $name = (string) ($r->user('sanctum')?->currentAccessToken()->name ?? '');

        return str_starts_with($name, 'device:') ? $name : self::principal($r);
    }

    public function boot(Registry $registry): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('site', EnsureSiteAccess::class);

        RateLimiter::for('login', fn (Request $r): Limit => Limit::perMinute((int) config('tanaad.rate_limits.login', 10))
            ->by(strtolower(trim((string) $r->input('username'))).'|'.$r->ip()));
        RateLimiter::for('pin-login', fn (Request $r): Limit => Limit::perMinute((int) config('tanaad.rate_limits.pin_login', 5))
            ->by(strtolower(trim((string) $r->input('username'))).'|'.$r->ip()));
        // General API: per signed-in user, per IP when anonymous. Ingest: per device (device-bound token), else per user.
        RateLimiter::for('api', fn (Request $r): Limit => Limit::perMinute((int) config('tanaad.rate_limits.api', 1200))
            ->by(self::principal($r)));
        RateLimiter::for('ingest', fn (Request $r): Limit => Limit::perMinute((int) config('tanaad.rate_limits.ingest', 120))
            ->by('ingest|'.self::deviceKey($r)));

        $registry
            ->command(RegisterDevice::class, RegisterDeviceHandler::class, IdentityAuthorizer::class)
            ->command(AssignRole::class, AssignRoleHandler::class, IdentityAuthorizer::class)
            ->command(OpenDeviceSession::class, OpenDeviceSessionHandler::class, IdentityAuthorizer::class)
            ->projectors(IdentityProjector::class);
    }
}
