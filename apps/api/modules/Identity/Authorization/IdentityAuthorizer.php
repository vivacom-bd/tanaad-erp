<?php

declare(strict_types=1);

namespace Tanaad\Identity\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Commands\OpenDeviceSession;
use Tanaad\Identity\Commands\RegisterDevice;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/** Only a Group Admin registers devices or grants roles. Device sessions are system-opened. */
final class IdentityAuthorizer implements Authorizer
{
    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        if ($command instanceof OpenDeviceSession) {
            return;
        }
        $actorId = $command->context()->actorId;
        $actor = $actorId === null ? null : User::query()->with('siteRoles')->find($actorId);
        if ($actor === null) {
            throw Rejection::forbidden('Sign in first.');
        }
        if (($command instanceof RegisterDevice || $command instanceof AssignRole) && ! $this->access->holdsAny($actor, null, [Role::GroupAdmin])) {
            throw Rejection::forbidden('Only a Group Admin may do this.');
        }
    }
}
