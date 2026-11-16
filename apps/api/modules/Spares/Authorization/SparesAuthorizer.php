<?php

declare(strict_types=1);

namespace Tanaad\Spares\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Spares\Commands\IssuePart;
use Tanaad\Spares\Commands\ReceivePart;
use Tanaad\Spares\Commands\ReportBreakdown;
use Tanaad\Spares\Commands\ResolveBreakdown;
use Tanaad\Spares\Commands\SavePart;

/**
 * As the prototype's nav. The catalogue is master data: the site manager (or a group admin) keeps it.
 * Parts come in through the site manager or storekeeper; they go out only through the storekeeper
 * or supervisor, whose own signed-in session is the accountability the prototype got from a PIN.
 * Mechanic, site manager and supervisor report and resolve breakdowns.
 */
final class SparesAuthorizer implements Authorizer
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
            throw Rejection::forbidden('Spares are kept per site.');
        }
        if ($command instanceof SavePart) {
            if ($this->access->holdsAny($actor, null, [Role::GroupAdmin]) || $this->access->holdsAny($actor, $siteId, [Role::SiteManager])) {
                return;
            }
            throw Rejection::forbidden('Only the Site Manager or a Group Admin may change the parts catalogue.');
        }
        [$roles, $message] = match (true) {
            $command instanceof ReceivePart => [[Role::Storekeeper, Role::SiteManager], 'Only the Storekeeper or Site Manager may receive spares.'],
            $command instanceof IssuePart => [[Role::Storekeeper, Role::Supervisor], 'Only the Storekeeper or Supervisor of this site may issue spares.'],
            $command instanceof ReportBreakdown, $command instanceof ResolveBreakdown => [[Role::Mechanic, Role::SiteManager, Role::Supervisor], 'Only a Mechanic, Supervisor or Site Manager may report or resolve a breakdown.'],
            default => throw new \LogicException('Unknown Spares command.'),
        };
        if (! $this->access->holdsAny($actor, $siteId, $roles)) {
            throw Rejection::forbidden($message);
        }
    }

    /** @param bool $breakdowns the breakdown ledger and downtime cost rather than the spares store */
    public function canRead(User $actor, string $siteId, bool $breakdowns = false): bool
    {
        return $this->access->canAct($actor, $siteId, self::readRoles($breakdowns));
    }

    /** @return list<string> */
    public static function readRoles(bool $breakdowns): array
    {
        return $breakdowns
            ? [Role::Mechanic->value, Role::SiteManager->value, Role::Supervisor->value]
            : [Role::Mechanic->value, Role::Storekeeper->value, Role::SiteManager->value];
    }
}
