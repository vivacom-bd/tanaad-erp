<?php

declare(strict_types=1);

namespace Tanaad\Group\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/** Payload is the event payload as sent; SendBriefHandler validates and normalises it. Group-wide: no site. */
final readonly class SendBrief implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(private CommandContext $context, public array $payload) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
