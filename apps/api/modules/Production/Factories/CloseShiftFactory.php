<?php

declare(strict_types=1);

namespace Tanaad\Production\Factories;

use Tanaad\Production\Commands\CloseShift;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in CloseShiftHandler so HTTP and sync share one rule set. */
final class CloseShiftFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new CloseShift($context, $envelope->payload);
    }
}
