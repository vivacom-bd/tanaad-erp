<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Illuminate\Support\Str;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\GiveAdvance;
use Tanaad\People\Models\Advance;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Currency;

/**
 * An advance against future pay. The next settlement run for the person deducts its balance.
 * Advances are paid in cash: the Cash module books people.advance_given (by type string) as a
 * cash-out on given_on, so that cash day must be open, as for cash payouts. Whole shillings.
 */
final class GiveAdvanceHandler implements Handler
{
    public function __construct(private readonly DayLocks $dayLocks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof GiveAdvance) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $personId = $p->uuid('person_id');
        $amount = $p->num('amount', 0.0, true);
        $reason = $p->str('reason');
        $today = Sites::today($site, $command);
        $givenOn = $p->optDate('given_on') ?? $today;
        // Not in the future: the upper bound is the command's own business day (occurred_at in the
        // site calendar), so an offline advance queued yesterday still lands on yesterday.
        if ($givenOn > $today) {
            $p->error('given_on', "must not be after {$today}");
        }
        $advanceId = $p->optUuid('advance_id') ?? (string) Str::uuid7();
        $currency = (string) ($site->currency_primary ?? 'UGX');
        if ($amount > 0 && ! Currency::fits($amount, $currency)) {
            $p->error('amount', Currency::minorUnitsOf($currency) === 0
                ? "must be a whole number of {$currency}" : 'must have at most '.Currency::minorUnitsOf($currency).' decimal places');
        } elseif ($amount > 0 && CreateSettlementRunHandler::whole($amount)->isZero()) {
            $p->error('amount', 'must be at least 1 whole shilling');
        }
        $p->check();
        // The id becomes both the advance id and its cash entry id: a reused one is a duplicate, not a 500.
        if (Advance::query()->whereKey($advanceId)->exists() || CashEntry::query()->whereKey($advanceId)->exists()) {
            throw Rejection::duplicateFact("Advance {$advanceId} is already recorded.");
        }
        $person = Sites::person($personId);
        if ($person->status !== 'active') {
            throw Rejection::stateConflict("{$person->full_name} is archived.");
        }
        if (! Sites::engagedAt($person, $site)) {
            throw Rejection::stateConflict("{$person->full_name} is not engaged at this site.");
        }

        $this->dayLocks->assertOpen($site->id, 'cash', $givenOn);

        return [Event::from($command->context(), 'people.advance_given', [
            'advance_id' => $advanceId, 'person_id' => $personId, 'amount' => (string) CreateSettlementRunHandler::whole($amount),
            'reason' => $reason, 'given_on' => $givenOn,
        ])];
    }
}
