<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\RecordPayout;
use Tanaad\People\Models\Payout;
use Tanaad\People\Models\SettlementLine;
use Tanaad\People\Models\SettlementRun;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Pays one line of an approved run, for its full net. The event payload is the shape the
 * Cash module consumes: {payout_id, settlement_run_id, person_id, amount, method, paid_at, reference?}.
 * A line is identified by run + person (a run holds one line per person). A cash payout
 * lands in that day's cash book, so the cash day must be open.
 */
final class RecordPayoutHandler implements Handler
{
    public const array METHODS = ['cash', 'mobile_money', 'bank'];

    public function __construct(private readonly DayLocks $dayLocks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordPayout) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $runId = $p->uuid('settlement_run_id');
        $personId = $p->uuid('person_id');
        $method = $p->oneOf('method', self::METHODS);
        $reference = $p->optStr('reference', 64);
        $paidAtRaw = $p->optStr('paid_at', 64);
        $paidAt = $command->context()->occurredAt;
        if ($paidAtRaw !== null) {
            try {
                $paidAt = CarbonImmutable::parse($paidAtRaw);
            } catch (\Throwable) {
                $p->error('paid_at', 'must be an ISO date-time');
            }
        }
        $payoutId = $p->optUuid('payout_id') ?? (string) Str::uuid7();
        $p->check();

        $run = SettlementRun::query()->where(['id' => $runId, 'site_id' => $site->id])->first()
            ?? throw Rejection::notFound('Settlement run', $runId);
        if ($run->status === 'created') {
            throw Rejection::stateConflict('Approve the settlement run before paying it.');
        }
        $line = SettlementLine::query()->where(['settlement_run_id' => $runId, 'person_id' => $personId])->first()
            ?? throw Rejection::notFound('Settlement line', "{$runId} {$personId}");
        if ($line->status === 'paid') {
            throw Rejection::duplicateFact('This line is already paid.');
        }
        // A cash payout's id is also its cash entry id: a reused one is a duplicate, not a 500.
        if (Payout::query()->whereKey($payoutId)->exists() || CashEntry::query()->whereKey($payoutId)->exists()) {
            throw Rejection::duplicateFact("Payout {$payoutId} is already recorded.");
        }
        if ($run->status === 'cancelled' || $line->status !== 'unpaid') {
            throw Rejection::stateConflict('This settlement run is cancelled and cannot be paid.');
        }
        if ((float) $line->net <= 0) {
            throw Rejection::stateConflict('Nothing to pay: advances cover the whole line.');
        }
        // In the site's timezone, so the Cash projector books it on the local day.
        $paidAt = $site->calendar()->local($paidAt);
        if ($method === 'cash') {
            $this->dayLocks->assertOpen($site->id, 'cash', $paidAt->toDateString());
        }

        return [Event::from($command->context(), 'people.payout_recorded', [
            'payout_id' => $payoutId, 'settlement_run_id' => $runId, 'person_id' => $personId,
            'amount' => CreateSettlementRunHandler::money($line->net), 'method' => $method, 'paid_at' => $paidAt->toIso8601String(),
            'reference' => $reference,
        ])];
    }
}
