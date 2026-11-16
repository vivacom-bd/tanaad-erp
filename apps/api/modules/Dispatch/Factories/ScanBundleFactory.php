<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Factories;

use Tanaad\Dispatch\Commands\ScanBundle;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

final class ScanBundleFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new ScanBundle($context, $envelope->payload);
    }
}
