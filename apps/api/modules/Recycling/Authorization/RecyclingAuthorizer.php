<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Recycling\Commands\PayCollector;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * As the prototype's nav: the scale weigh-in is recorded by the supervisor, site manager,
 * gate, storekeeper or cashier of the site; only its cashier or site manager pays a collector
 * (it is a cash-out). The collector ledger is read by the supervisor, site manager, cashier
 * and group roles.
 */
final class RecyclingAuthorizer implements Authorizer
{
    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        $actorId = $command->context()->actorId;
        $actor = $actorId === null ? null : User::query()->with('siteRoles')->find($actorId);
        if ($actor === null) {
            throw Rejection::forbidden('Sign in first.');
        }
        $siteId = $command->context()->siteId;
        if ($siteId === null) {
            throw Rejection::forbidden('Collector intake is kept per site.');
        }
        if ($command instanceof PayCollector) {
            if (! $this->access->holdsAny($actor, $siteId, [Role::Cashier, Role::SiteManager])) {
                throw Rejection::forbidden('Only the Cashier or Site Manager of this site may pay a collector.');
            }

            return;
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::Supervisor, Role::SiteManager, Role::Gate, Role::Storekeeper, Role::Cashier])) {
            throw Rejection::forbidden('Only the scale crew of this site may record a collector delivery.');
        }
    }

    /** @param bool $ledger the collector ledger (money owed) rather than the day's weigh-ins */
    public function canRead(User $actor, string $siteId, bool $ledger = false): bool
    {
        $roles = $ledger
            ? [Role::Supervisor->value, Role::SiteManager->value, Role::Cashier->value]
            : [Role::Supervisor->value, Role::SiteManager->value, Role::Gate->value, Role::Storekeeper->value, Role::Cashier->value];

        return $this->access->canAct($actor, $siteId, $roles);
    }
}
