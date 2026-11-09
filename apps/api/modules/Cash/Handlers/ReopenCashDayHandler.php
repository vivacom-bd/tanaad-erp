<?php

declare(strict_types=1);

namespace Tanaad\Cash\Handlers;

use Tanaad\Cash\Commands\ReopenCashDay;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** A site manager reopens a closed day with a reason; CashProjector clears the day lock. */
final class ReopenCashDayHandler implements Handler
{
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
        $p->check();

        $cashDay = CashDay::query()->find(CashBook::dayId($siteId, $day)) ?? throw Rejection::notFound('Cash day', $day);
        if ($cashDay->status !== 'closed') {
            throw Rejection::stateConflict("The cash day {$day} is not closed.");
        }

        return [Event::from($ctx, 'cash.day_reopened', [
            'cash_day_id' => $cashDay->id,
            'day' => $day,
            'reason' => $reason,
        ], reason: $reason)];
    }
}
