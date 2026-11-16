<?php

declare(strict_types=1);

namespace Tanaad\Stock\Factories;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Stock\Commands\MoveStock;

final class MoveStockFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $payload = $envelope->payload;
        if ($envelope->type === 'stock.adjusted') {
            $payload['kind'] = 'adjust';
        } elseif ($envelope->type === 'stock.moved') {
            $payload['kind'] ??= 'transfer';
        }

        return new MoveStock($context, $payload);
    }
}
