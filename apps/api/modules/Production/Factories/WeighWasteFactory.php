<?php

declare(strict_types=1);

namespace Tanaad\Production\Factories;

use Tanaad\Production\Commands\WeighWaste;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in WeighWasteHandler so HTTP and sync share one rule set. */
final class WeighWasteFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new WeighWaste($context, $envelope->payload);
    }
}
