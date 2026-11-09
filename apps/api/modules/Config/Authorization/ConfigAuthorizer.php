<?php

declare(strict_types=1);

namespace Tanaad\Config\Authorization;

use Tanaad\Config\Commands\ChangeRefList;
use Tanaad\Config\Commands\SetFxRate;
use Tanaad\Config\Commands\UpdateCompanyProfile;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * A Group Admin configures everything. A Site Manager configures their own site, except
 * group-wide items, FX rates and the company profile that prints on documents.
 */
final class ConfigAuthorizer implements Authorizer
{
    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        $actorId = $command->context()->actorId;
        $actor = $actorId === null ? null : User::query()->with('siteRoles')->find($actorId);
        if ($actor === null) {
            throw Rejection::forbidden('Sign in first.');
        }
        if ($this->access->holdsAny($actor, null, [Role::GroupAdmin])) {
            return;
        }
        if ($command instanceof SetFxRate || $command instanceof UpdateCompanyProfile) {
            throw Rejection::forbidden('Only a Group Admin may do this.');
        }
        $siteId = $command->context()->siteId;
        $groupWideRef = $command instanceof ChangeRefList && array_key_exists('site_id', $command->payload) && $command->payload['site_id'] === null;
        if ($siteId === null || $groupWideRef) {
            throw Rejection::forbidden('Only a Group Admin may change group-wide settings.');
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::SiteManager])) {
            throw Rejection::forbidden('Only a Site Manager or Group Admin may change settings.');
        }
    }
}
