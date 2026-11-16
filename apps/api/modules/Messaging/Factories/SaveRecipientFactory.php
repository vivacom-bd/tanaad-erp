<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Factories;

use Tanaad\Messaging\Commands\SaveRecipient;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

final class SaveRecipientFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return new SaveRecipient($context, $envelope->payload);
    }
}
