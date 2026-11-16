<?php

declare(strict_types=1);

namespace Tanaad\Production\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Payload is the event payload as sent; RecordProductionEntryHandler validates and normalises it. */
final readonly class RecordProductionEntry implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
