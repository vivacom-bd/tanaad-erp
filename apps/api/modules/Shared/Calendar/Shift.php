<?php

declare(strict_types=1);

namespace Tanaad\Shared\Calendar;

/** One row of a site's shift table, as the prototype config has it: kind, starts, ends ("HH:MM"). */
final readonly class Shift
{
    public function __construct(public string $kind, public string $starts, public string $ends) {}

    /** @param array{kind: string, starts: string, ends: string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['kind'], $row['starts'], $row['ends']);
    }

    public function startMinute(): int
    {
        return self::minutes($this->starts);
    }

    public function endMinute(): int
    {
        return self::minutes($this->ends);
    }

    public function crossesMidnight(): bool
    {
        return $this->endMinute() <= $this->startMinute();
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
