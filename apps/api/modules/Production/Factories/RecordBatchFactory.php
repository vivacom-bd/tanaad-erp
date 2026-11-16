<?php

declare(strict_types=1);

namespace Tanaad\Production\Factories;

use Tanaad\Production\Commands\RecordBatch;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in RecordBatchHandler so HTTP and sync share one rule set. */
final class RecordBatchFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new RecordBatch($context, $envelope->payload);
    }
}
