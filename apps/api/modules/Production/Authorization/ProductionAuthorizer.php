<?php

declare(strict_types=1);

namespace Tanaad\Production\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * ADR-006 for production. The supervisor or the site manager of the command's own site records
 * entries, batches, waste and shift closes (a re-log by either corrects an entry). Group roles and
 * the site's manager and supervisor read.
 */
final class ProductionAuthorizer implements Authorizer
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
            throw Rejection::forbidden('Production is recorded per site.');
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::Supervisor, Role::SiteManager])) {
            throw Rejection::forbidden('Only the Supervisor or Site Manager of this site may record production.');
        }
    }

    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, [Role::Supervisor->value, Role::SiteManager->value]);
    }
}
