<?php

declare(strict_types=1);

namespace Tanaad\Orders\Handlers;

use Tanaad\Orders\Commands\HoldOrder;
use Tanaad\Orders\Support\OrderDecision;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Handler;

/** The credit desk's hold decision; see OrderDecision. */
final class HoldOrderHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof HoldOrder) {
            throw new \LogicException('Wrong command for '.self::class);
        }

        return OrderDecision::decide($command->context(), $command->payload, false);
    }
}
