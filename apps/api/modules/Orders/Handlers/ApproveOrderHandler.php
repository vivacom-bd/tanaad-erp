<?php

declare(strict_types=1);

namespace Tanaad\Orders\Handlers;

use Tanaad\Orders\Commands\ApproveOrder;
use Tanaad\Orders\Support\OrderDecision;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Handler;

/** The credit desk's approve decision; see OrderDecision. */
final class ApproveOrderHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ApproveOrder) {
            throw new \LogicException('Wrong command for '.self::class);
        }

        return OrderDecision::decide($command->context(), $command->payload, true);
    }
}
