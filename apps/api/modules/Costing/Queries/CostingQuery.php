<?php

declare(strict_types=1);

namespace Tanaad\Costing\Queries;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Tanaad\Costing\Support\CostBook;
use Tanaad\Costing\Support\Period;
use Tanaad\Shared\Money\Rounding;

/**
 * Reads. A closed period answers from its frozen snapshot (provisional: false); anything else is
 * computed live from the cash-closed days in range and flagged provisional: true.
 */
final class CostingQuery
{
    /** @return array<string, mixed> */
    public function figures(string $siteId, Period $period): array
    {
        $row = DB::table('costing_periods')->where('id', CostBook::periodId($siteId, $period->from, $period->to))->first();
        if ($row !== null && $row->status === 'closed') {
            /** @var array<string, mixed> $snapshot */
            $snapshot = json_decode((string) $row->snapshot, true, 512, JSON_THROW_ON_ERROR);

            return ['site_id' => $siteId, 'period' => $period->label, 'provisional' => false, 'status' => 'closed', 'version' => (int) $row->version, 'closed_at' => $row->closed_at, ...$snapshot];
        }

        return ['site_id' => $siteId, 'period' => $period->label, 'provisional' => true, 'status' => $row === null ? 'open' : (string) $row->status,
            'version' => $row === null ? 0 : (int) $row->version, 'closed_at' => null, ...CostBook::compute($siteId, $period->from, $period->to)];
    }

    /** @return array<string, mixed> P&L only (no unit costs) */
    public function pnl(string $siteId, Period $period): array
    {
        $f = $this->figures($siteId, $period);
        unset($f['unit_costs']);

        return $f;
    }

    /** @return array<string, mixed> */
    public function unitCost(string $siteId, Period $period): array
    {
        $f = $this->figures($siteId, $period);

        return ['site_id' => $siteId, 'period' => $f['period'], 'from' => $f['from'], 'to' => $f['to'], 'provisional' => $f['provisional'],
            'closed_days' => $f['closed_days'], 'open_days' => $f['open_days'], 'material' => $f['material'], 'unit_costs' => $f['unit_costs']];
    }

    /**
     * Every site side by side plus the group total in UGX (and USD at the reference rate).
     *
     * @return array<string, mixed>
     */
    public function group(Period $period): array
    {
        $sites = [];
        $revenue = BigDecimal::zero();
        $costs = BigDecimal::zero();
        $net = BigDecimal::zero();
        $byCat = [];
        $provisional = false;
        foreach (DB::table('sites')->orderBy('code')->get(['id', 'code']) as $s) {
            $f = $this->pnl((string) $s->id, $period);
            $sites[] = ['site' => (string) $s->code, ...$f];
            $provisional = $provisional || $f['provisional'] || $f['open_days'] > 0;
            $revenue = $revenue->plus((string) $f['revenue']['total']);
            $costs = $costs->plus((string) $f['costs']['total']);
            $net = $net->plus((string) $f['net']);
            foreach ($f['costs']['by_category'] as $cat => $v) {
                $byCat[$cat] = ($byCat[$cat] ?? BigDecimal::zero())->plus((string) $v);
            }
        }
        ksort($byCat);
        $fx = CostBook::fx($period->to);
        $usd = fn (BigDecimal $v): ?float => $fx === null ? null : Rounding::money($v->dividedBy($fx, 12, RoundingMode::HalfUp));

        return [
            'period' => $period->label, 'from' => $period->from, 'to' => $period->to, 'provisional' => $provisional, 'sites' => $sites,
            'group' => [
                'revenue' => Rounding::money($revenue), 'costs' => Rounding::money($costs), 'net' => Rounding::money($net),
                'by_category' => array_map(fn (BigDecimal $v): float => Rounding::money($v), $byCat),
                'margin_pct' => $revenue->isPositive() ? Rounding::to($net->dividedBy($revenue, 12, RoundingMode::HalfUp)->multipliedBy(100), 1)->toFloat() : 0.0,
                'fx_usd_ugx' => $fx === null ? null : (float) $fx,
                'usd' => ['revenue' => $usd($revenue), 'costs' => $usd($costs), 'net' => $usd($net)],
            ],
        ];
    }
}
