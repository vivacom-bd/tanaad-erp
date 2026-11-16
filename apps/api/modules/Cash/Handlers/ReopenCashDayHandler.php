<?php

declare(strict_types=1);

namespace Tanaad\Cash\Handlers;

use Tanaad\Cash\Commands\ReopenCashDay;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Config\Support\Payload;
use Tanaad\Identity\ManagerApproval;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * A site manager reopens a closed day with a reason; CashProjector clears the day lock.
 * On a shared cashier tablet the manager authorises by PIN: the payload carries
 * approver_user_id + approver_pin, verified here (role at this site + PIN, with lockout).
 * The event records authorized_by (the approver, or the acting manager); never the PIN.
 */
final class ReopenCashDayHandler implements Handler
{
    /** Roles that may authorise a reopen; matches CashAuthorizer's own-session rule. */
    public const array APPROVER_ROLES = [Role::SiteManager];

    public function __construct(private readonly ManagerApproval $approval) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof ReopenCashDay) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $day = $p->date('day');
        $reason = $p->str('reason', 500);
        $approverId = $p->optStr('approver_user_id', 64);
        $pin = $p->optStr('approver_pin', 8);
        if (($approverId === null) !== ($pin === null)) {
            $p->error($approverId === null ? 'approver_user_id' : 'approver_pin', 'required with the other approver field');
        }
        $p->check();

        $cashDay = CashDay::query()->lockForUpdate()->find(CashBook::dayId($siteId, $day)) ?? throw Rejection::notFound('Cash day', $day);
        if ($cashDay->status !== 'closed') {
            throw Rejection::stateConflict("The cash day {$day} is not closed.");
        }

        $authorizedBy = $approverId !== null && $pin !== null
            ? $this->approval->verify($siteId, $approverId, $pin, self::APPROVER_ROLES)->id
            : $ctx->actorId;

        return [Event::from($ctx, 'cash.day_reopened', [
            'cash_day_id' => $cashDay->id,
            'day' => $day,
            'reason' => $reason,
            'authorized_by' => $authorizedBy,
        ], reason: $reason)];
    }
}
