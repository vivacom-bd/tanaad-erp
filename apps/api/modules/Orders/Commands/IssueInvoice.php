<?php

declare(strict_types=1);

namespace Tanaad\Orders\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Issues the invoice of an approved order, once. Payload is the event payload as sent; the handler validates it. */
final readonly class IssueInvoice implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
