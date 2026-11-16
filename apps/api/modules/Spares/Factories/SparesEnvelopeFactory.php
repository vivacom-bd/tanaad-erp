<?php

declare(strict_types=1);

namespace Tanaad\Spares\Factories;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Spares\Commands\IssuePart;
use Tanaad\Spares\Commands\ReceivePart;
use Tanaad\Spares\Commands\ReportBreakdown;
use Tanaad\Spares\Commands\ResolveBreakdown;
use Tanaad\Spares\Commands\SavePart;

/** Offline envelopes → Spares commands. Validation lives in the handlers so HTTP and sync share one rule set. */
final class SparesEnvelopeFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return match ($envelope->type) {
            'spares.part_saved' => new SavePart($context, $envelope->payload),
            'spares.part_received' => new ReceivePart($context, $envelope->payload),
            'spares.part_issued' => new IssuePart($context, $envelope->payload),
            'spares.breakdown_reported' => new ReportBreakdown($context, $envelope->payload),
            'spares.breakdown_resolved' => new ResolveBreakdown($context, $envelope->payload),
            default => throw Rejection::unknownType($envelope->type),
        };
    }
}
