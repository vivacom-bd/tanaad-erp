<?php

declare(strict_types=1);

namespace Tanaad\Costing\Authorization;

use Tanaad\Costing\Commands\ClosePeriod;
use Tanaad\Costing\Commands\SaveCostMapping;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Costing is per site. The site's manager, the CEO and the group admin close and reopen
 * periods; the scheduler (no actor) may only close. The cost-category mapping is
 * configuration: the site's manager or the group admin. Reads: the site's manager and group roles.
 */
final class CostingAuthorizer implements Authorizer
{
    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        $siteId = $command->context()->siteId;
        if ($siteId === null) {
            throw Rejection::forbidden('Costing is kept per site.');
        }
        $actorId = $command->context()->actorId;
        if ($actorId === null && $command instanceof ClosePeriod) {
            return; // costing:close-period runs as the system
        }
        $actor = $actorId === null ? null : User::query()->with('siteRoles')->find($actorId);
        if ($actor === null) {
            throw Rejection::forbidden('Sign in first.');
        }
        if ($command instanceof SaveCostMapping) {
            if (! $this->access->holdsAny($actor, $siteId, [Role::SiteManager, Role::GroupAdmin])) {
                throw Rejection::forbidden('Only the Site Manager or the group admin may change the cost mapping.');
            }

            return;
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::SiteManager, Role::Ceo, Role::GroupAdmin])) {
            throw Rejection::forbidden('Only the Site Manager, the CEO or the group admin may close or reopen a costing period.');
        }
    }

    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, [Role::SiteManager->value]);
    }

    public function canReadGroup(User $actor): bool
    {
        return $this->access->holdsAny($actor, null, [Role::Ceo, Role::GroupAdmin]);
    }
}
