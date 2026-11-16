<?php

declare(strict_types=1);

namespace Tanaad\Stock\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\ReopenStockCount;

/**
 * ADR-006 for stock. The storekeeper, supervisor or site manager of the command's own site
 * receives, moves, scraps, transfers and counts (a transfer is received under the destination
 * site). Only that site's manager reopens a counted day. Group roles read stock only.
 */
final class StockAuthorizer implements Authorizer
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
            throw Rejection::forbidden('Stock is kept per site.');
        }
        if ($command instanceof ReopenStockCount) {
            if (! $this->access->holdsAny($actor, $siteId, [Role::SiteManager])) {
                throw Rejection::forbidden('Only the Site Manager may reopen a counted day.');
            }

            return;
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::Storekeeper, Role::Supervisor, Role::SiteManager])) {
            throw Rejection::forbidden('Only the Storekeeper, Supervisor or Site Manager of this site may change its stock.');
        }
    }

    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, [Role::Storekeeper->value, Role::Supervisor->value, Role::SiteManager->value]);
    }
}
