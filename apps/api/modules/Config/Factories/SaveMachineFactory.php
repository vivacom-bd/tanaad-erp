<?php

declare(strict_types=1);

namespace Tanaad\Config\Factories;

use Tanaad\Config\Commands\SaveMachine;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in SaveMachineHandler so HTTP and sync share one rule set. */
final class SaveMachineFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new SaveMachine($context, $envelope->payload);
    }
}
