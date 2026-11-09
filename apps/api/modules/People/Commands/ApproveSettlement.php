<?php

declare(strict_types=1);

namespace Tanaad\People\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Approves a created run. The creator may not approve their own run (ADR-006). */
final readonly class ApproveSettlement implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
