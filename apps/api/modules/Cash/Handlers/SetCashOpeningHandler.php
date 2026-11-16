<?php

declare(strict_types=1);

namespace Tanaad\Cash\Handlers;

use Tanaad\Cash\Commands\SetCashOpening;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Sets a cash day's opening balance (e.g. the cash in the box on the very first day).
 * Allowed only while the day has no entries and is not closed; opens the day first if needed.
 * Payload: {day, amount}. Event cash.opening_set: {cash_day_id, day, opening}.
 */
final class SetCashOpeningHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof SetCashOpening) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $day = $p->date('day');
        CashBook::refuseFuture($p, 'day', $day, CashBook::today($siteId, $ctx));
        $amount = CashBook::money($p->num('amount'));
        $p->check();

        $this->locks->assertOpen($siteId, CashBook::LEDGER, $day);
        $dayId = CashBook::dayId($siteId, $day);
        $existing = CashDay::query()->find($dayId);
        if ($existing !== null && $existing->status === 'closed') {
            throw Rejection::stateConflict("The cash day {$day} is closed.");
        }
        if (CashEntry::query()->where('cash_day_id', $dayId)->exists()) {
            throw Rejection::stateConflict("The cash day {$day} already has entries; its opening balance can no longer change.");
        }

        return [...RecordCashEntryHandler::openIfNeeded($ctx, $siteId, $day, $existing->currency ?? 'UGX'), Event::from($ctx, 'cash.opening_set', [
            'cash_day_id' => $dayId,
            'day' => $day,
            'opening' => $amount,
        ])];
    }
}
