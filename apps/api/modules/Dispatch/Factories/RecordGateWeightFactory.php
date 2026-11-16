<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Factories;

use Tanaad\Dispatch\Commands\RecordGateWeight;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

final class RecordGateWeightFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new RecordGateWeight($context, $envelope->payload);
    }
}
