<?php

declare(strict_types=1);

namespace Tanaad\Cash\Handlers;

use Illuminate\Support\Str;
use Tanaad\Cash\Commands\CorrectCashEntry;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Replaces an entry with a corrected one. The original row and event stay; the correction references both. */
final class CorrectCashEntryHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof CorrectCashEntry) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $originalId = $p->uuid('corrects_entry_id');
        $reason = $p->str('reason', 500);
        $entry = RecordCashEntryHandler::fields($p);
        $entryId = $p->optUuid('entry_id') ?? (string) Str::uuid7();
        $p->check();

        $original = CashEntry::query()->where(['id' => $originalId, 'site_id' => $siteId])->first()
            ?? throw Rejection::notFound('Cash entry', $originalId);
        if ($original->superseded_by !== null) {
            throw Rejection::stateConflict('This entry has already been corrected; correct the latest version.');
        }
        if ($original->payout_id !== null) {
            throw Rejection::stateConflict('This entry comes from a payout; correct the payout instead.');
        }
        $day = $original->dayString();
        $this->locks->assertOpen($siteId, CashBook::LEDGER, $day);
        if ($entry['currency'] !== $original->currency) {
            throw Rejection::invalidPayload(['currency' => "must be {$original->currency}, the currency of this cash day"]);
        }

        return [Event::from($ctx, 'cash.entry_corrected', [
            'entry_id' => $entryId,
            'corrects_entry_id' => $original->id,
            'cash_day_id' => $original->cash_day_id,
            'day' => $day,
            ...$entry,
            'reason' => $reason,
        ], correctsEventId: $original->event_id, reason: $reason)];
    }
}
