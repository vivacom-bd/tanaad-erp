<?php

declare(strict_types=1);

namespace Tanaad\Group\Queries;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Shared\Money\Rounding;

/**
 * Per-site headline tiles for the CEO cockpit. Reads other modules' read tables by name only,
 * and skips what is not there yet (receivables need an `invoices` table).
 */
final class CockpitQuery
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ExceptionRules $rules) {}

    public function today(): string
    {
        return \Carbon\CarbonImmutable::now($this->rules->timezone())->toDateString();
    }

    /** @return array<string, mixed> */
    public function build(?string $day = null): array
    {
        $day ??= $this->today();
        $sites = [];
        foreach ($this->db->table('sites')->whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'name', 'currency_primary']) as $s) {
            $sites[] = $this->site((string) $s->id, (string) $s->code, (string) $s->name, (string) $s->currency_primary, $day);
        }

        return ['day' => $day, 'sites' => $sites];
    }

    /** @return array<string, mixed> */
    public function site(string $id, string $code, string $name, string $currency, string $day): array
    {
        return [
            'siteId' => $id, 'siteCode' => $code, 'name' => $name, 'currency' => $currency,
            'output' => $this->output($id, $day),
            'headcount' => $this->headcount($id, $day),
            'cash' => $this->cash($id, $day),
            'exceptions' => $this->exceptions($id),
            'stock' => $this->stock($id),
            'dispatches' => $this->dispatches($id),
            'machinesDown' => $this->machinesDown($id),
            'receivables' => $this->receivables($id),
        ];
    }

    /** @return array<string, mixed> */
    private function output(string $id, string $day): array
    {
        $r = $this->db->table('production_entries')->where('site_id', $id)->where('day', $day)->whereNull('deleted_at')
            ->selectRaw('count(*) as entries, coalesce(sum(good_units),0) as good, coalesce(sum(reject_units),0) as rejects, coalesce(sum(output_kg),0) as out_kg, coalesce(sum(input_kg),0) as in_kg, coalesce(sum(case when below_benchmark then 1 else 0 end),0) as below')
            ->first();
        $r = (array) $r;

        return ['entries' => (int) ($r['entries'] ?? 0), 'goodUnits' => (int) ($r['good'] ?? 0), 'rejectUnits' => (int) ($r['rejects'] ?? 0),
            'outputKg' => (float) ($r['out_kg'] ?? 0), 'inputKg' => (float) ($r['in_kg'] ?? 0), 'belowBenchmark' => (int) ($r['below'] ?? 0)];
    }

    /** @return array<string, mixed> */
    private function headcount(string $id, string $day): array
    {
        $rows = $this->db->table('attendance')->where('site_id', $id)->where('day', $day)->whereNull('deleted_at')
            ->selectRaw('status, count(*) as n, coalesce(sum(overtime_hours),0) as ot')->groupBy('status')->get();
        $by = [];
        $ot = 0.0;
        foreach ($rows as $r) {
            $by[(string) $r->status] = (int) $r->n;
            $ot += (float) $r->ot;
        }
        $present = $by['present'] ?? 0;
        $half = $by['half'] ?? 0;

        return ['present' => $present, 'half' => $half, 'absent' => $by['absent'] ?? 0, 'total' => $present + $half, 'overtimeHours' => $ot];
    }

    /** @return array<string, mixed> */
    private function cash(string $id, string $day): array
    {
        $latest = $this->db->table('cash_days')->where('site_id', $id)->where('day', '<=', $day)->whereNull('deleted_at')->orderByDesc('day')->first();
        $today = $latest !== null && (string) $latest->day === $day ? $latest : null;
        if ($latest === null) {
            return ['inSafe' => 0.0, 'asOf' => null, 'closeStatus' => 'none', 'variance' => null];
        }
        $sums = $this->db->table('cash_entries')->where('cash_day_id', $latest->id)->whereNull('superseded_by')->whereNull('deleted_at')
            ->selectRaw("coalesce(sum(case when direction = 'in' then amount else 0 end),0) as total_in, coalesce(sum(case when direction = 'out' then amount else 0 end),0) as total_out")->first();
        $sums = (array) $sums;
        $safe = BigDecimal::of((string) $latest->opening)->plus((string) ($sums['total_in'] ?? 0))->minus((string) ($sums['total_out'] ?? 0));

        return [
            'inSafe' => Rounding::money($safe), 'asOf' => (string) $latest->day,
            'closeStatus' => $today === null ? 'none' : (string) $today->status,
            'variance' => $today?->variance === null ? null : (float) $today->variance,
        ];
    }

    /** @return array<string, int> */
    private function exceptions(string $id): array
    {
        $out = ['critical' => 0, 'warning' => 0, 'info' => 0, 'held' => 0, 'total' => 0];
        $rows = $this->db->table('exceptions')->where('site_id', $id)->whereIn('status', ExceptionRules::ACTIVE_STATUSES)
            ->selectRaw('severity, status, count(*) as n')->groupBy('severity', 'status')->get();
        foreach ($rows as $r) {
            $out[(string) $r->severity] = ($out[(string) $r->severity] ?? 0) + (int) $r->n;
            $out['total'] += (int) $r->n;
            if ($r->status === 'held') {
                $out['held'] += (int) $r->n;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function stock(string $id): array
    {
        $r = $this->db->table('stock_on_hand')->where('site_id', $id)->where('bundles', '>', 0)
            ->selectRaw('coalesce(sum(bundles),0) as bundles, coalesce(sum(kg),0) as kg, count(distinct product_id) as products')->first();
        $r = (array) $r;

        return ['bundles' => (int) ($r['bundles'] ?? 0), 'kg' => (float) ($r['kg'] ?? 0), 'products' => (int) ($r['products'] ?? 0)];
    }

    /** @return array<string, int> */
    private function dispatches(string $id): array
    {
        $rows = $this->db->table('dispatches')->where('site_id', $id)->whereNull('delivered_at')->selectRaw('gate_status, count(*) as n')->groupBy('gate_status')->get();
        $by = [];
        foreach ($rows as $r) {
            $by[(string) $r->gate_status] = (int) $r->n;
        }

        return ['pending' => $by['pending'] ?? 0, 'held' => $by['hold'] ?? 0, 'inTransit' => ($by['pass'] ?? 0) + ($by['overridden'] ?? 0)];
    }

    /** @return array<string, mixed> */
    private function machinesDown(string $id): array
    {
        $rows = $this->db->table('breakdowns')->where('site_id', $id)->whereNull('resolved_at')->whereNull('deleted_at')->pluck('machine_id');
        $codes = $rows->isEmpty() ? [] : $this->db->table('machines')->whereIn('id', $rows->all())->orderBy('code')->pluck('code')->map(fn ($c): string => (string) $c)->all();

        return ['count' => $rows->count(), 'machines' => array_values($codes)];
    }

    /**
     * Unpaid invoice balances (invoices.balance = total - paid_amount, kept by OrdersProjector).
     * `open` is the UGX amount only; other currencies are never summed with it and come in `byCurrency`.
     *
     * @return array{open: float, count: int, byCurrency: array<string, float>}|null null when invoicing is not installed
     */
    private function receivables(string $id): ?array
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumns('invoices', ['site_id', 'balance', 'currency'])) {
            return null;
        }
        $rows = $this->db->table('invoices')->where('site_id', $id)->where('balance', '>', 0)->whereNull('deleted_at')
            ->groupBy('currency')->selectRaw('currency, sum(balance) as open, count(*) as n')->get();
        $by = [];
        $count = 0;
        foreach ($rows as $r) {
            $by[(string) $r->currency] = (float) $r->open;
            $count += (int) $r->n;
        }

        return ['open' => $by['UGX'] ?? 0.0, 'count' => $count, 'byCurrency' => $by];
    }
}
