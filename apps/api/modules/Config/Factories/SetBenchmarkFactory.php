<?php

declare(strict_types=1);

namespace Tanaad\Config\Factories;

use Tanaad\Config\Commands\SetBenchmark;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in SetBenchmarkHandler so HTTP and sync share one rule set. */
final class SetBenchmarkFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new SetBenchmark($context, $envelope->payload);
    }
}
