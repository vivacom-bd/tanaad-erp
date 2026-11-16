<?php

declare(strict_types=1);

namespace Tanaad\Production\Support;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Calendar\Calendar;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/**
 * Read-side helpers shared by handlers and queries. Master data (sites, stages, machines,
 * products, benchmarks, ref_items, site_settings) is read from Config's tables by name.
 *
 * Stage roles are derived from the configured stages, never from site codes:
 * - the wall stage (machine wall, output, kg out) is the stage with the most non-retired machines
 *   (ties: the later stage) — weave at mats, extrude at bags, pelletise at recycling;
 * - the input stage (kg in) is the earliest stage, by sequence, that has machines.
 */
final class ProductionBook
{
    /** Ref list whose items are the waste kinds a site can weigh. Items are user-managed. */
    public const string WASTE_LIST = 'waste_source';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return object{id: string, code: string, timezone: string, shifts: string} */
    public function site(string $siteId): object
    {
        /** @var object{id: string, code: string, timezone: string, shifts: string}|null $site */
        $site = $this->db->table('sites')->where('id', $siteId)->first(['id', 'code', 'timezone', 'shifts']);

        return $site ?? throw Rejection::notFound('Site', $siteId);
    }

    public function calendar(string $siteId): Calendar
    {
        $site = $this->site($siteId);
        $rows = json_decode((string) $site->shifts, true);

        return Calendar::forSite((string) $site->code, is_array($rows) ? array_values($rows) : [], (string) $site->timezone);
    }

    /** @return list<string> shift kinds configured for the site; day|night when none are */
    public function shiftKinds(string $siteId): array
    {
        $rows = json_decode((string) $this->site($siteId)->shifts, true);
        $kinds = is_array($rows) ? array_values(array_filter(array_map(fn ($r) => is_array($r) ? ($r['kind'] ?? null) : null, $rows), 'is_string')) : [];

        return $kinds === [] ? ['day', 'night'] : $kinds;
    }

    public function shiftId(string $siteId, string $day, string $kind): string
    {
        return $this->calendar($siteId)->shiftId($day, $kind);
    }

    public function today(string $siteId, ?CarbonImmutable $now = null): string
    {
        return $this->calendar($siteId)->businessDay($now ?? CarbonImmutable::now());
    }

    /** Fraction of the shift elapsed (0.1..1) when it is the site's current shift, otherwise 1 — shiftProgress() in the prototype. */
    public function progress(string $siteId, string $day, string $kind, ?CarbonImmutable $now = null): float
    {
        $now ??= CarbonImmutable::now();
        $cal = $this->calendar($siteId);
        $shift = $cal->shiftAt($now);
        if ($shift === null || $shift->kind !== $kind || $cal->businessDay($now) !== $day) {
            return 1.0;
        }
        $local = $cal->local($now);
        $minute = $local->hour * 60 + $local->minute;
        $length = ($shift->endMinute() - $shift->startMinute() + 1440) % 1440 ?: 1440;
        $elapsed = ($minute - $shift->startMinute() + 1440) % 1440;

        return max(0.1, min(1.0, $elapsed / $length));
    }

    /** Units expected so far: the benchmark scaled by shift progress (expectedSoFar on the machine wall). */
    public static function expected(?float $benchmark, float $progress): ?int
    {
        return $benchmark !== null && $benchmark > 0 ? (int) round($benchmark * $progress) : null;
    }

    /** Percent of expected (whole, rounded), as the machine wall shows it; null when nothing is expected. */
    public static function pctOfExpected(int $good, ?int $expected): ?int
    {
        return $expected !== null && $expected > 0 ? (int) round(($good / $expected) * 100) : null;
    }

    /** @return object{id: string, site_id: string, code: string, kind: string, stage_code: string, status: string}|null */
    public function machine(string $siteId, string $machineId): ?object
    {
        /** @var object{id: string, site_id: string, code: string, kind: string, stage_code: string, status: string}|null */
        return $this->db->table('machines')->where(['id' => $machineId, 'site_id' => $siteId])->whereNull('deleted_at')->first();
    }

    public function stageExists(string $siteId, string $code): bool
    {
        return $this->db->table('stages')->where(['site_id' => $siteId, 'code' => $code])->whereNull('deleted_at')->exists();
    }

    public function productExists(string $siteId, string $productId): bool
    {
        return $this->db->table('products')->where(['id' => $productId, 'site_id' => $siteId])->whereNull('deleted_at')->exists();
    }

    /** The ref item code of an active waste kind (matched by code or id) at the site or group-wide. */
    public function wasteKind(string $siteId, string $ref): ?string
    {
        $row = $this->db->table('ref_items')->where('list', self::WASTE_LIST)->where('active', true)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('site_id', $siteId)->orWhereNull('site_id'))
            ->where(fn ($q) => $q->where('code', $ref)->orWhere('id', $ref))
            ->first(['id', 'code']);

        return $row === null ? null : (string) ($row->code ?? $row->id);
    }

    public function benchmark(string $machineId, ?string $productId): ?float
    {
        if ($productId === null) {
            return null;
        }
        $v = $this->db->table('benchmarks')->where(['machine_id' => $machineId, 'product_id' => $productId])->whereNull('deleted_at')->value('units_per_shift');

        return is_numeric($v) ? (float) $v : null;
    }

    public function setting(string $siteId, string $column): float
    {
        $v = $this->db->table('site_settings')->where('site_id', $siteId)->whereNull('deleted_at')->value($column);

        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** @return list<object{code: string, sequence: int, produces_label: ?string}> */
    public function stages(string $siteId): array
    {
        /** @var list<object{code: string, sequence: int, produces_label: ?string}> */
        return $this->db->table('stages')->where('site_id', $siteId)->whereNull('deleted_at')->orderBy('sequence')
            ->get(['code', 'sequence', 'produces_label'])->all();
    }

    /** @return array<string, int> stage code => non-retired machine count */
    private function machineCounts(string $siteId): array
    {
        return $this->db->table('machines')->where('site_id', $siteId)->whereNull('deleted_at')->where('status', '!=', 'retired')
            ->groupBy('stage_code')->selectRaw('stage_code, count(*) as n')->pluck('n', 'stage_code')->map(fn ($n): int => (int) $n)->all();
    }

    public function wallStage(string $siteId): ?string
    {
        $counts = $this->machineCounts($siteId);
        $best = null;
        $bestN = 0;
        foreach ($this->stages($siteId) as $s) {
            $n = $counts[$s->code] ?? 0;
            if ($n > 0 && $n >= $bestN) {
                [$best, $bestN] = [$s->code, $n];
            }
        }

        return $best;
    }

    public function inputStage(string $siteId): ?string
    {
        $counts = $this->machineCounts($siteId);
        foreach ($this->stages($siteId) as $s) {
            if (($counts[$s->code] ?? 0) > 0) {
                return $s->code;
            }
        }

        return null;
    }

    /** @return list<string> active machines at the wall stage with no entry in the shift */
    public function unlogged(string $siteId, string $shiftId): array
    {
        $stage = $this->wallStage($siteId);
        if ($stage === null) {
            return [];
        }
        $logged = $this->db->table('production_entries')->where('shift_id', $shiftId)->pluck('machine_id')->all();

        return array_values($this->db->table('machines')->where(['site_id' => $siteId, 'stage_code' => $stage, 'status' => 'active'])->whereNull('deleted_at')
            ->whereNotIn('id', $logged)->orderBy('code')->pluck('id')->map(fn ($id): string => (string) $id)->all());
    }

    /**
     * massBalanceDay() of the prototype: kg in at the input stage, kg out at the wall stage,
     * waste from weighings, WIP from in-stock labels of the stages in between (when a labels table exists).
     *
     * @return array{kgIn: float, kgOut: float, wasteKg: float, wipDeltaKg: float, allowedLossPct: float, allowedLossKg: float, gapKg: float, gapPct: float, state: string}
     */
    public function massBalance(string $siteId, string $day, float $extraWasteKg = 0.0): array
    {
        $in = $this->inputStage($siteId);
        $out = $this->wallStage($siteId);
        $sum = fn (?string $stage, string $col): float => $stage === null ? 0.0
            : Rounding::kg((string) ($this->db->table('production_entries')->where(['site_id' => $siteId, 'day' => $day, 'stage_code' => $stage])->sum($col) ?: '0'));
        $kgIn = $sum($in, 'input_kg');
        $kgOut = $sum($out, 'output_kg');
        $waste = BigDecimal::of((string) ($this->db->table('waste_weighings')->where(['site_id' => $siteId, 'day' => $day])->sum('kg') ?: '0'))
            ->plus(BigDecimal::of((string) $extraWasteKg));
        $wasteKg = Rounding::kg($waste);
        $wip = $this->wipKg($siteId, $day, $in, $out);
        $allowed = $this->setting($siteId, 'mass_gap_pct');

        return ['kgIn' => $kgIn, 'kgOut' => $kgOut, 'wasteKg' => $wasteKg, 'wipDeltaKg' => $wip, 'allowedLossPct' => $allowed,
            ...Arithmetic::massBalance(['kgIn' => $kgIn, 'kgOut' => $kgOut, 'wasteKg' => $wasteKg, 'wipDeltaKg' => $wip, 'allowedLossPct' => $allowed])];
    }

    private function wipKg(string $siteId, string $day, ?string $in, ?string $out): float
    {
        if (! Schema::hasTable('labels') || ! Schema::hasColumns('labels', ['site_id', 'kind', 'status', 'day', 'qty_kg'])) {
            return 0.0;
        }
        $kinds = [];
        $between = false;
        foreach ($this->stages($siteId) as $s) {
            if ($s->code === $out) {
                break;
            }
            if ($between && $s->produces_label !== null) {
                $kinds[] = $s->produces_label;
            }
            $between = $between || $s->code === $in;
        }
        if ($kinds === []) {
            return 0.0;
        }

        return Rounding::kg((string) ($this->db->table('labels')->where(['site_id' => $siteId, 'day' => $day, 'status' => 'in_stock'])->whereIn('kind', $kinds)->sum('qty_kg') ?: '0'));
    }
}
