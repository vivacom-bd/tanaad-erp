<?php

declare(strict_types=1);

namespace Tanaad\People\Commands;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Marks one person for one business day and shift. With $correction the existing mark is
 * replaced by a people.attendance_corrected event that points at it and carries $reason.
 */
final readonly class MarkAttendance implements Command
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private CommandContext $context,
        public array $payload,
        public bool $correction = false,
        public ?string $correctsEventId = null,
        public ?string $reason = null,
    ) {}

    public function context(): CommandContext
    {
        return $this->context;
    }
}
