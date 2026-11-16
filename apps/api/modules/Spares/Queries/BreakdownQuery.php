<?php

declare(strict_types=1);

namespace Tanaad\Spares\Queries;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Money\Rounding;

/** Breakdowns, the machines that are down now, and the downtime cost of a period. */
final class BreakdownQuery
{
    public const int MAX_DAYS = 366;

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array<string, mixed>> newest first */
    public function breakdowns(string $siteId): array
    {
        $rows = $this->db->table('breakdowns')->where('site_id', $siteId)->whereNull('deleted_at')->orderByDesc('started_at')->orderByDesc('seq')->get();

        return array_values($rows->map(fn (\stdClass $r): array => self::breakdown($r))->all());
    }

    /**
     * Machines with an unresolved breakdown (Config owns machines; "down" is read from here).
     *
     * @return list<array{machineId: string, breakdownId: string, since: string}>
     */
    public function machinesDown(string $siteId): array
    {
        $rows = $this->db->table('breakdowns')->where('site_id', $siteId)->whereNull('resolved_at')->whereNull('deleted_at')->orderBy('started_at')->get();

        return array_values($rows->map(fn (\stdClass $r): array => [
            'machineId' => (string) $r->machine_id, 'breakdownId' => (string) $r->id, 'since' => self::iso((string) $r->started_at),
        ])->all());
    }

    /**
     * Per machine over [from, to] (site calendar days, inclusive): downtime minutes inside the period (an open
     * breakdown runs to now), breakdowns that overlap it, and the spares issued to the machine in it.
     *
     * @return array<string, mixed>
     */
    public function downtimeCost(string $siteId, string $from, string $to, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $tz = (string) ($this->db->table('sites')->where('id', $siteId)->value('timezone') ?: config('tanaad.timezone', 'UTC'));
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->addDay()->startOfDay();

        $per = [];
        $startUtc = $start->utc()->format('Y-m-d H:i:s.u');
        $endUtc = $end->utc()->format('Y-m-d H:i:s.u');
        $rows = $this->db->table('breakdowns')->where('site_id', $siteId)->whereNull('deleted_at')
            ->where('started_at', '<', $endUtc)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $startUtc))->get();
        foreach ($rows as $r) {
            $b = CarbonImmutable::parse((string) $r->started_at, 'UTC');
            $e = $r->resolved_at === null ? $now : CarbonImmutable::parse((string) $r->resolved_at, 'UTC');
            $lo = $b->greaterThan($start) ? $b : $start;
            $hi = $e->lessThan($end) ? $e : $end;
            $per[(string) $r->machine_id]['minutes'] = ($per[(string) $r->machine_id]['minutes'] ?? 0) + max(0, intdiv($hi->getTimestamp() - $lo->getTimestamp(), 60));
            $per[(string) $r->machine_id]['count'] = ($per[(string) $r->machine_id]['count'] ?? 0) + 1;
        }
        $issues = $this->db->table('part_issues')->where('site_id', $siteId)->whereNull('deleted_at')
            ->where('at', '>=', $startUtc)->where('at', '<', $endUtc)->groupBy('machine_id')->selectRaw('machine_id, SUM(cost) AS cost')->get();
        foreach ($issues as $i) {
            $per[(string) $i->machine_id]['cost'] = (string) $i->cost;
        }
        $codes = $this->db->table('machines')->whereIn('id', array_keys($per))->pluck('code', 'id');

        $machines = [];
        $total = BigDecimal::zero();
        $minutes = 0;
        foreach ($per as $id => $v) {
            $machines[] = [
                'machineId' => $id, 'machineCode' => (string) ($codes[$id] ?? $id), 'breakdowns' => $v['count'] ?? 0,
                'downtimeMinutes' => $v['minutes'] ?? 0, 'downtimeHours' => round(($v['minutes'] ?? 0) / 60, 2),
                'sparesCost' => Rounding::money($v['cost'] ?? '0'),
            ];
            $total = $total->plus($v['cost'] ?? '0');
            $minutes += $v['minutes'] ?? 0;
        }
        usort($machines, fn (array $a, array $b): int => [$b['downtimeMinutes'], $b['sparesCost'], $a['machineCode']] <=> [$a['downtimeMinutes'], $a['sparesCost'], $b['machineCode']]);

        return [
            'from' => $from, 'to' => $to, 'machines' => $machines,
            'totals' => ['downtimeMinutes' => $minutes, 'downtimeHours' => round($minutes / 60, 2), 'sparesCost' => Rounding::money($total)],
        ];
    }

    /** @return array<string, mixed> */
    public static function breakdown(\stdClass $r): array
    {
        return [
            'id' => (string) $r->id, 'siteId' => (string) $r->site_id, 'machineId' => (string) $r->machine_id, 'cause' => (string) $r->cause,
            'startedAt' => self::iso((string) $r->started_at), 'resolvedAt' => $r->resolved_at === null ? null : self::iso((string) $r->resolved_at),
            'durationMinutes' => $r->duration_minutes === null ? null : (int) $r->duration_minutes,
            'sparesCost' => Rounding::money((string) $r->spares_cost), 'open' => $r->resolved_at === null,
        ];
    }

    private static function iso(string $dt): string
    {
        return CarbonImmutable::parse($dt, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
