<?php

declare(strict_types=1);

namespace Tanaad\People\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Cancels a run that has not been paid out, giving back the advances it deducted. */
final readonly class CancelSettlementRun implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
