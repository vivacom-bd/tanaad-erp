<?php

declare(strict_types=1);

namespace Tanaad\People\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;

/** Reads the terms of an engagement (packages/domain Engagement) from a payload. */
final class EngagementTerms
{
    public const array TYPES = ['permanent', 'contract', 'casual'];

    public const array RATE_BASES = ['month', 'shift', 'day', 'piece'];

    public const array CYCLES = ['weekly', 'end_of_term', 'monthly'];

    /** @return array<string, mixed> engagement_id, type, start_date, end_date, term_days, rate_basis, rate, currency, settlement_cycle */
    public static function read(Payload $p, string $defaultStart): array
    {
        $start = $p->optDate('start_date') ?? $defaultStart;
        $termDays = $p->optInt('term_days', 1);
        $end = $p->optDate('end_date') ?? ($termDays === null ? null : self::addDays($start, $termDays));
        if ($end !== null && $end < $start) {
            $p->error('end_date', 'must not be before start_date');
        }

        return [
            'engagement_id' => $p->optUuid('engagement_id') ?? (string) Str::uuid7(),
            'type' => $p->oneOf('type', self::TYPES),
            'start_date' => $start,
            'end_date' => $end,
            'term_days' => $termDays,
            'rate_basis' => $p->oneOf('rate_basis', self::RATE_BASES),
            'rate' => $p->num('rate'),
            'currency' => $p->currency('currency'),
            'settlement_cycle' => $p->oneOf('settlement_cycle', self::CYCLES),
        ];
    }

    public static function addDays(string $day, int $days): string
    {
        return CarbonImmutable::parse($day)->addDays($days)->toDateString();
    }
}
