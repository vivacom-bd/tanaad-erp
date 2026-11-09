<?php

declare(strict_types=1);

namespace Tanaad\Group\Authorization;

use Tanaad\Group\Commands\DecideException;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Group\Commands\SendBrief;
use Tanaad\Group\Models\BusinessException;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * ADR-006 for exceptions.
 * Raise: the system (no actor) for the policy and scheduler kinds only; otherwise the CEO,
 * group admin, or the site manager or supervisor of the command's site.
 * Decide: the CEO and group admin anywhere; the site's manager for site-level exceptions
 * (owner role not ceo/group_admin). Nobody decides an exception they raised.
 */
final class GroupAuthorizer implements Authorizer
{
    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        $siteId = $command->context()->siteId;
        if ($command instanceof SendBrief) {
            $this->authoriseBrief($command);

            return;
        }
        if ($siteId === null) {
            throw Rejection::forbidden('Exceptions are raised per site.');
        }
        $actorId = $command->context()->actorId;
        if ($actorId === null) {
            if ($command instanceof RaiseException && in_array($command->payload['kind'] ?? null, ExceptionRules::SYSTEM_KINDS, true)) {
                return;
            }
            throw Rejection::forbidden('Sign in first.');
        }
        $actor = User::query()->with('siteRoles')->find($actorId);
        if ($actor === null) {
            throw Rejection::forbidden('Sign in first.');
        }

        if ($command instanceof RaiseException) {
            if (! $this->access->holdsAny($actor, $siteId, [Role::Ceo, Role::GroupAdmin, Role::SiteManager, Role::Supervisor])) {
                throw Rejection::forbidden('Only the CEO, the group admin, or this site\'s manager or supervisor may raise an exception.');
            }

            return;
        }
        if (! $command instanceof DecideException) {
            throw Rejection::forbidden();
        }
        $id = $command->payload['exception_id'] ?? null;
        $exception = is_string($id) ? BusinessException::query()->find($id) : null;
        if ($exception !== null && $exception->raised_by === $actorId) {
            throw Rejection::forbidden('You raised this exception; someone else must decide it.');
        }
        if ($this->access->holdsAny($actor, $siteId, [Role::Ceo, Role::GroupAdmin])) {
            return;
        }
        $siteLevel = $exception === null || ! in_array($exception->owner_role, ExceptionRules::GROUP_OWNERS, true);
        if ($siteLevel && $this->access->holdsAny($actor, $siteId, [Role::SiteManager])) {
            return;
        }
        throw Rejection::forbidden('Only the CEO, the group admin, or this site\'s manager for site-level exceptions may decide.');
    }

    /** Briefs are group-wide: the CEO and group admin send them. */
    private function authoriseBrief(SendBrief $command): void
    {
        $actorId = $command->context()->actorId;
        $actor = $actorId === null ? null : User::query()->with('siteRoles')->find($actorId);
        if ($actor === null || ! $this->access->holdsAny($actor, null, [Role::Ceo, Role::GroupAdmin])) {
            throw Rejection::forbidden('Only the CEO or the group admin may send the brief.');
        }
    }

    /** Group-wide list: CEO and group admin. */
    public function canReadAll(User $actor): bool
    {
        return $this->access->hasGroupRole($actor);
    }

    /** A site's list: group roles, and the site's manager and supervisor. */
    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, [Role::SiteManager->value, Role::Supervisor->value]);
    }
}
