<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

final readonly class AddNote implements Command
{
    public function __construct(
        private CommandContext $context,
        public string $day,
        public string $text,
        public bool $urgent = false,
    ) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
