<?php

declare(strict_types=1);

namespace Tanaad\Labels\Factories;

use Tanaad\Labels\Commands\ConsumeLabel;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

final class ConsumeLabelFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new ConsumeLabel($context, $envelope->payload);
    }
}
