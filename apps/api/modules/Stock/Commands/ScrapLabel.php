<?php

declare(strict_types=1);

namespace Tanaad\Stock\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Payload is the event payload as sent; ScrapLabelHandler validates and normalises it. */
final readonly class ScrapLabel implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
