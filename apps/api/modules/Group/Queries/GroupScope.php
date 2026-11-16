<?php

declare(strict_types=1);

namespace Tanaad\Group\Queries;

use Illuminate\Http\Request;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Rejection;

/** Who may read the group-wide views: group roles see every site, a site manager only their own. */
final class GroupScope
{
    public function __construct(private readonly Access $access) {}

    public function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('Sign in first.');
        }

        return $user->loadMissing('siteRoles');
    }

    public function groupOnly(Request $request, string $what): User
    {
        $user = $this->user($request);
        if (! $this->access->hasGroupRole($user)) {
            throw Rejection::forbidden("Only group roles read {$what}.");
        }

        return $user;
    }

    /**
     * Site ids the user may read: null = every site (and group-wide events).
     *
     * @return list<string>|null
     */
    public function readableSites(User $user): ?array
    {
        if ($this->access->hasGroupRole($user)) {
            return null;
        }
        $ids = $user->siteRoles->filter(fn (UserSiteRole $r): bool => $r->role === Role::SiteManager->value && $r->site_id !== null)
            ->map(fn (UserSiteRole $r): string => (string) $r->site_id)->unique()->values()->all();
        $ids = array_values($ids);
        if ($ids === []) {
            throw Rejection::forbidden('Only group roles and site managers read the audit feed.');
        }

        return $ids;
    }
}
