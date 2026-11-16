<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Authorization;

use Tanaad\Dispatch\Commands\ConfirmDelivery;
use Tanaad\Dispatch\Commands\OverrideGate;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * ADR-006 for dispatch, all site-scoped. The site's storekeeper, gate clerk or site manager load,
 * weigh and confirm delivery (the site's driver may also confirm delivery). Only the site manager
 * overrides a held gate; OverrideGateHandler then enforces separation of duties. Group roles and the
 * supervisor read but do not write; the driver reads (and confirms delivery).
 */
final class DispatchAuthorizer implements Authorizer
{
    public const array READ_ROLES = ['gate', 'storekeeper', 'supervisor', 'site_manager', 'driver'];

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
            throw Rejection::forbidden('Dispatches belong to a site.');
        }
        $roles = match (true) {
            $command instanceof OverrideGate => [Role::SiteManager],
            $command instanceof ConfirmDelivery => [Role::Storekeeper, Role::Gate, Role::SiteManager, Role::Driver],
            default => [Role::Storekeeper, Role::Gate, Role::SiteManager],
        };
        if (! $this->access->holdsAny($actor, $siteId, $roles)) {
            throw Rejection::forbidden($command instanceof OverrideGate
                ? 'Only the Site Manager of this site may override the gate.'
                : 'Only the Storekeeper, Gate or Site Manager of this site may do this.');
        }
    }

    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, self::READ_ROLES);
    }
}
