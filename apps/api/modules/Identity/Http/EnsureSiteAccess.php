<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Route middleware `site`: the authenticated user must hold a role at `{site}` or a group
 * role. Optional role list: `site:cashier,site_manager` (group roles always pass).
 */
final class EnsureSiteAccess
{
    public function __construct(private readonly Access $access) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $site = CurrentSite::resolve($request);
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('Sign in first.');
        }
        if (! $this->access->canAct($user, $site->id, array_values($roles))) {
            throw Rejection::forbidden("You have no access to {$site->code}.");
        }

        return $next($request);
    }
}
