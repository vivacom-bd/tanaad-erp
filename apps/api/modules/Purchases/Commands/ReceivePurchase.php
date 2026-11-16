<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Receives a purchase (goods in; no cash or stock is booked here). Payload is the event payload as sent; the handler validates it. */
final readonly class ReceivePurchase implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
