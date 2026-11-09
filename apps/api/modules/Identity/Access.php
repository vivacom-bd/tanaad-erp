<?php

declare(strict_types=1);

namespace Tanaad\Identity;

use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;

/**
 * Role × site questions, answered from user_site_roles (ADR-006).
 */
final class Access
{
    public function hasGroupRole(User $user): bool
    {
        return $user->siteRoles->contains(fn (UserSiteRole $r): bool => Role::from($r->role)->isGroup());
    }

    /** @return list<string> roles the user holds at the site, plus group roles */
    public function rolesAt(User $user, ?string $siteId): array
    {
        return array_values($user->siteRoles
            ->filter(fn (UserSiteRole $r): bool => $r->site_id === null || $r->site_id === $siteId)
            ->map(fn (UserSiteRole $r): string => $r->role)
            ->unique()->values()->all());
    }

    /** @return list<string>|null site ids the user may see; null means every site */
    public function siteIds(User $user): ?array
    {
        if ($this->hasGroupRole($user)) {
            return null;
        }

        return array_values($user->siteRoles->pluck('site_id')->filter()->unique()->map(fn ($id): string => (string) $id)->values()->all());
    }

    /**
     * May the user act at the site? With $roles given, one of them must be held there;
     * group roles always qualify.
     *
     * @param  list<string>  $roles
     */
    public function canAct(User $user, string $siteId, array $roles = []): bool
    {
        if ($this->hasGroupRole($user)) {
            return true;
        }
        $held = $this->rolesAt($user, $siteId);
        if ($held === []) {
            return false;
        }

        return $roles === [] || array_intersect($roles, $held) !== [];
    }

    /** @param list<Role> $roles */
    public function holdsAny(User $user, ?string $siteId, array $roles): bool
    {
        $wanted = array_map(fn (Role $r): string => $r->value, $roles);
        if ($this->hasGroupRole($user) && array_intersect($wanted, [Role::Ceo->value, Role::GroupAdmin->value]) !== []) {
            return $user->siteRoles->contains(fn (UserSiteRole $r): bool => in_array($r->role, $wanted, true));
        }

        return array_intersect($wanted, $this->rolesAt($user, $siteId)) !== [];
    }
}
