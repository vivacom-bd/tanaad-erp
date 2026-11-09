<?php

declare(strict_types=1);

namespace Tanaad\Cash\Factories;

use Tanaad\Cash\Commands\CloseCashDay;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in CloseCashDayHandler so HTTP and sync share one rule set. The envelope reason fills a missing payload reason. */
final class CloseCashDayFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $payload = $envelope->payload;
        if ($envelope->reason !== null && ! isset($payload['reason'])) {
            $payload['reason'] = $envelope->reason;
        }

        return new CloseCashDay($context, $payload);
    }
}
