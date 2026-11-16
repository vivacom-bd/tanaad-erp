<?php

declare(strict_types=1);

namespace Tanaad\Stock\Factories;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Stock\Commands\SendTransfer;

final class SendTransferFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new SendTransfer($context, $envelope->payload);
    }
}
