<?php

declare(strict_types=1);

namespace Tanaad\Cash\Authorization;

use Tanaad\Cash\Commands\ReopenCashDay;
use Tanaad\Cash\Commands\SetCashOpening;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * ADR-006 for the cash book. The cashier (or the site manager) of the command's own site
 * records, corrects and closes; only that site's manager reopens a closed day. The opening
 * balance may be set by the cashier, the site manager or the CEO. Other group roles read
 * the cash book but do not write it.
 */
final class CashAuthorizer implements Authorizer
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
            throw Rejection::forbidden('Cash is kept per site.');
        }
        if ($command instanceof ReopenCashDay) {
            if (! $this->access->holdsAny($actor, $siteId, [Role::SiteManager])) {
                throw Rejection::forbidden('Only the Site Manager may reopen a closed cash day.');
            }

            return;
        }
        if ($command instanceof SetCashOpening) {
            if (! $this->access->holdsAny($actor, $siteId, [Role::Cashier, Role::SiteManager, Role::Ceo])) {
                throw Rejection::forbidden('Only the Cashier, the Site Manager or the CEO may set the opening balance.');
            }

            return;
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::Cashier, Role::SiteManager])) {
            throw Rejection::forbidden('Only the Cashier or Site Manager of this site may write the cash book.');
        }
    }

    /** Who may read a site's cash book: its cashier and manager, and group roles. */
    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, [Role::Cashier->value, Role::SiteManager->value]);
    }
}
