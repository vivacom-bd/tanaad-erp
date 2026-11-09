<?php

declare(strict_types=1);

namespace Tanaad\Cash\Factories;

use Tanaad\Cash\Commands\SetCashOpening;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in SetCashOpeningHandler so HTTP and sync share one rule set. */
final class SetCashOpeningFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new SetCashOpening($context, $envelope->payload);
    }
}
