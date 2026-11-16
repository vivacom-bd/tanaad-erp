<?php

declare(strict_types=1);

namespace Tanaad\Identity;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Ledger\RejectionCode;

/**
 * A manager authorises an action on someone else's session (a shared cashier tablet) by
 * entering their PIN there. The server checks that the approver holds one of the roles at the
 * site and that the PIN matches, through PinLockout so wrong PINs count towards the lockout.
 *
 * Every failure (unknown user, wrong role, other site, wrong PIN, locked) gives the same
 * forbidden rejection with code "approval_failed"; the PIN is never stored or logged.
 *
 * Wrong-PIN counts written inside a rolled-back command transaction are lost, so callers that
 * accept a PIN over HTTP call verify() before dispatching (the handler then verifies again).
 */
final class ManagerApproval
{
    public function __construct(
        private readonly Access $access,
        private readonly PinLockout $pins,
        private readonly Hasher $hasher,
    ) {}

    /**
     * @param  list<Role>  $roles  roles the approver must hold at the site
     *
     * @throws Rejection
     */
    public function verify(string $siteId, string $approverId, string $pin, array $roles): User
    {
        $approver = Str::isUuid($approverId)
            ? User::query()->with('siteRoles')->where('active', true)->find($approverId)
            : null;
        if ($approver === null || ! $this->access->holdsAny($approver, $siteId, $roles)) {
            // Spend the same hashing time as a real check so the answer does not reveal the approver.
            $this->hasher->check($pin, $this->hasher->make('0000'));
            throw self::failed();
        }
        try {
            $this->pins->verify($approver, $pin);
        } catch (Rejection) {
            throw self::failed();
        }

        return $approver;
    }

    public static function failed(): Rejection
    {
        return Rejection::of(RejectionCode::Forbidden, 'Manager approval failed.', ['code' => 'approval_failed']);
    }
}
