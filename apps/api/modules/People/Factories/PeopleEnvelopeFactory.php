<?php

declare(strict_types=1);

namespace Tanaad\People\Factories;

use Tanaad\People\Commands\ApproveSettlement;
use Tanaad\People\Commands\ArchivePerson;
use Tanaad\People\Commands\CancelSettlementRun;
use Tanaad\People\Commands\CreateSettlementRun;
use Tanaad\People\Commands\EndEngagement;
use Tanaad\People\Commands\GiveAdvance;
use Tanaad\People\Commands\MarkAttendance;
use Tanaad\People\Commands\ReactivatePerson;
use Tanaad\People\Commands\RecordPayout;
use Tanaad\People\Commands\RegisterPerson;
use Tanaad\People\Commands\RenewEngagement;
use Tanaad\People\Commands\StartEngagement;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Offline envelopes → People commands. Field validation lives in the handlers so HTTP and
 * sync share one rule set. A people.person_registered envelope carries the first
 * engagement under payload.engagement; the server emits people.engagement_started for it.
 */
final class PeopleEnvelopeFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $p = $envelope->payload;

        return match ($envelope->type) {
            'people.person_registered' => new RegisterPerson($context, $p),
            'people.engagement_started' => new StartEngagement($context, $p),
            'people.engagement_renewed' => new RenewEngagement($context, $p),
            'people.engagement_ended' => new EndEngagement($context, $p),
            'people.attendance_marked' => new MarkAttendance($context, $p),
            'people.attendance_corrected' => new MarkAttendance($context, $p, true, $envelope->correctsEventId, $envelope->reason),
            'people.person_archived' => new ArchivePerson($context, $p),
            'people.person_reactivated' => new ReactivatePerson($context, $p),
            'people.settlement_run_created' => new CreateSettlementRun($context, $p),
            'people.settlement_approved' => new ApproveSettlement($context, $p),
            'people.settlement_cancelled' => new CancelSettlementRun($context, $p),
            'people.payout_recorded' => new RecordPayout($context, $p),
            'people.advance_given' => new GiveAdvance($context, $p),
            default => throw Rejection::unknownType($envelope->type),
        };
    }
}
