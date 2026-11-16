<?php

declare(strict_types=1);

namespace Tanaad\Group\Factories;

use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/** Field validation lives in the handler so HTTP and sync share one rule set. */
final class RaiseExceptionFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new RaiseException($context, $envelope->payload);
    }
}
