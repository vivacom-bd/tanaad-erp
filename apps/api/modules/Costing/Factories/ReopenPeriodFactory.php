<?php

declare(strict_types=1);

namespace Tanaad\Costing\Factories;

use Tanaad\Costing\Commands\ReopenPeriod;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Validation lives in ReopenPeriodHandler so HTTP and sync share one rule set. The envelope reason fills a missing payload reason. */
final class ReopenPeriodFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $payload = $envelope->payload;
        if ($envelope->reason !== null && ! isset($payload['reason'])) {
            $payload['reason'] = $envelope->reason;
        }

        return new ReopenPeriod($context, $payload);
    }
}
