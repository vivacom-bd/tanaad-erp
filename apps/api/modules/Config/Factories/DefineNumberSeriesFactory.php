<?php

declare(strict_types=1);

namespace Tanaad\Config\Factories;

use Tanaad\Config\Commands\DefineNumberSeries;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in DefineNumberSeriesHandler so HTTP and sync share one rule set. */
final class DefineNumberSeriesFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new DefineNumberSeries($context, $envelope->payload);
    }
}
