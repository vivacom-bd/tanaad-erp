<?php

declare(strict_types=1);

namespace Tanaad\People\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Computes a settlement run for a site, period and kind; the event carries every line. */
final readonly class CreateSettlementRun implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
