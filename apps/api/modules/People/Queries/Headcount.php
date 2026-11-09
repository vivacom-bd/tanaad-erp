<?php

declare(strict_types=1);

namespace Tanaad\People\Queries;

use Tanaad\People\Models\Attendance;

/** Headcount per site, business day and shift. A half day counts as half a head. */
final class Headcount
{
    /** @return list<array{day: string, shift: string, present: int, half: int, absent: int, headcount: float}> */
    public function forSite(string $siteId, string $from, string $to, ?string $shift = null): array
    {
        $rows = Attendance::query()
            ->where('site_id', $siteId)->whereBetween('day', [$from, $to])->whereNull('deleted_at')
            ->when($shift !== null, fn ($q) => $q->where('shift_kind', $shift))
            ->selectRaw("day, shift_kind, sum(status = 'present') as present, sum(status = 'half') as half, sum(status = 'absent') as absent")
            ->groupBy('day', 'shift_kind')->orderBy('day')->orderBy('shift_kind')
            ->toBase()->get();

        return array_values($rows->map(fn (object $r): array => [
            'day' => substr((string) $r->day, 0, 10),
            'shift' => (string) $r->shift_kind,
            'present' => (int) $r->present,
            'half' => (int) $r->half,
            'absent' => (int) $r->absent,
            'headcount' => (int) $r->present + (int) $r->half / 2,
        ])->all());
    }
}
