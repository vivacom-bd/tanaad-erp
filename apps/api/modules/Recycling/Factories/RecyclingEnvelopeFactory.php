<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Factories;

use Tanaad\Recycling\Commands\PayCollector;
use Tanaad\Recycling\Commands\RecordCollectorIntake;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Shared\Ledger\Rejection;

/** Offline envelopes → Recycling commands. Validation lives in the handlers so HTTP and sync share one rule set. */
final class RecyclingEnvelopeFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return match ($envelope->type) {
            'recycling.collector_intake_recorded' => new RecordCollectorIntake($context, $envelope->payload),
            'recycling.collector_paid' => new PayCollector($context, $envelope->payload),
            default => throw Rejection::unknownType($envelope->type),
        };
    }
}
