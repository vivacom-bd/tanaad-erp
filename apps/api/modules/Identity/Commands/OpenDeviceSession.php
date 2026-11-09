<?php

declare(strict_types=1);

namespace Tanaad\Identity\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

final readonly class OpenDeviceSession implements Command
{
    public function __construct(private CommandContext $context, public string $userId, public string $role) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
