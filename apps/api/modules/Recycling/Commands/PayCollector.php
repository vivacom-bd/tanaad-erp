<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Pays a collector for one delivery, in cash. Payload is the event payload as sent; the handler validates it. */
final readonly class PayCollector implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
