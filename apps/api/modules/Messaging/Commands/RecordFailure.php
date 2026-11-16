<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Server-only: written by the delivery job. */
final readonly class RecordFailure implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
