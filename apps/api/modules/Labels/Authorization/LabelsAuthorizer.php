<?php

declare(strict_types=1);

namespace Tanaad\Labels\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * ADR-006 for labels. Supervisors, storekeepers and the site manager of the command's own
 * site print and consume labels. Group roles read labels but do not write them.
 */
final class LabelsAuthorizer implements Authorizer
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
            throw Rejection::forbidden('Labels belong to a site.');
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::Supervisor, Role::Storekeeper, Role::SiteManager])) {
            throw Rejection::forbidden('Only a Supervisor, Storekeeper or Site Manager of this site may print or consume labels.');
        }
    }

    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, [Role::Supervisor->value, Role::Storekeeper->value, Role::SiteManager->value]);
    }
}
