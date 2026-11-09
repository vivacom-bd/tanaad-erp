<?php

declare(strict_types=1);

namespace Tanaad\Identity\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

final readonly class RegisterDevice implements Command
{
    public function __construct(private CommandContext $context, public string $deviceId, public string $name, public string $kind = 'tablet') {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
