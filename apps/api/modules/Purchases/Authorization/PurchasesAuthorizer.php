<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Purchases\Commands\ReceivePurchase;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Cashiers still see Purchases (to pay suppliers out of the cash book) but do not record them:
 * the site's manager or storekeeper, or a group role (CEO, group admin), orders and edits;
 * goods are received by the site's manager or storekeeper.
 */
final class PurchasesAuthorizer implements Authorizer
{
    public const array READ = [Role::SiteManager, Role::Cashier, Role::Storekeeper, Role::Ceo, Role::GroupAdmin];

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
            throw Rejection::forbidden('Purchases are kept per site.');
        }
        if ($command instanceof ReceivePurchase) {
            if (! $this->access->holdsAny($actor, $siteId, [Role::SiteManager, Role::Storekeeper])) {
                throw Rejection::forbidden('Only the Site Manager or Storekeeper of this site may receive a purchase.');
            }

            return;
        }
        if (! $this->access->holdsAny($actor, $siteId, [Role::SiteManager, Role::Storekeeper, Role::Ceo, Role::GroupAdmin])) {
            throw Rejection::forbidden('Only the Site Manager or Storekeeper of this site, or the CEO or Group Admin, may record a purchase.');
        }
    }

    public function canRead(User $actor, string $siteId): bool
    {
        return $this->access->canAct($actor, $siteId, self::readRoles());
    }

    /** @return list<string> */
    public static function readRoles(): array
    {
        return array_map(fn (Role $r): string => $r->value, self::READ);
    }
}
