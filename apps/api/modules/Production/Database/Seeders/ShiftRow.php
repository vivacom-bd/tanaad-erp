<?php

declare(strict_types=1);

namespace Tanaad\Production\Database\Seeders;

/** One machine's entry for a shift while ProductionSeeder builds it. */
final class ShiftRow
{
    public function __construct(
        public readonly string $stage,
        public readonly string $machine,
        public readonly ?string $product,
        public int $units,
        public float $kg,
        public float $in,
        public readonly ?string $reason,
        public readonly int $down,
        public readonly float $unitKg,
    ) {}
}
