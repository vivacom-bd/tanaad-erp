<?php

declare(strict_types=1);

namespace Tanaad\Production\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\Production\Support\ProductionBook;

/** Reads {day, kind} (top level, or nested under "shift") and refuses a future business day. */
final class ShiftInput
{
    /** @return array{0: string, 1: string} */
    public static function read(Payload $p, ProductionBook $book, string $siteId, bool $kindRequired = true): array
    {
        $day = $p->date('day');
        $kind = $kindRequired ? $p->oneOf('kind', $book->shiftKinds($siteId)) : ($p->has('kind') ? $p->oneOf('kind', $book->shiftKinds($siteId)) : '');
        if ($day !== '' && $day > $book->today($siteId)) {
            $p->error('day', 'must not be in the future');
        }

        return [$day, $kind];
    }

    /**
     * Flattens {shift: {day, kind}} into day/kind.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function flatten(array $payload): array
    {
        $shift = $payload['shift'] ?? null;
        if (is_array($shift)) {
            $payload['day'] ??= $shift['day'] ?? null;
            $payload['kind'] ??= $shift['kind'] ?? null;
        }

        return $payload;
    }
}
