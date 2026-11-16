<?php

declare(strict_types=1);

namespace Tanaad\Costing\Factories;

use Tanaad\Costing\Commands\ClosePeriod;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Validation lives in ClosePeriodHandler so HTTP and sync share one rule set. The envelope reason fills a missing payload reason. */
final class ClosePeriodFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $payload = $envelope->payload;
        if ($envelope->reason !== null && ! isset($payload['reason'])) {
            $payload['reason'] = $envelope->reason;
        }

        return new ClosePeriod($context, $payload);
    }
}
