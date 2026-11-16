<?php

declare(strict_types=1);

namespace Tanaad\Costing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Shared\Money\Rounding;

/**
 * P&L and unit cost over the CASH-CLOSED days of a range, mirroring the prototype
 * (apps/web/src/data/selectors.ts unitCost, features/group/Pnl.tsx booksFor):
 *
 *   revenue        = cash_sales: manual cash sales (cash.entry_recorded in, category "sale") + local orders' confirmed cash
 *                    (orders.cash_confirmed, on its cash day, invoiced or not), plus invoiced: invoice totals less the cash
 *                    prepaid for them (orders.invoiced, on issued_day), UGX; invoice payments are receipts, never revenue
 *   costs[cat]     = Σ cost lines whose source_key maps to cat (DEFAULT_MAP, overridden by cost_category_map)
 *   net            = revenue − Σ costs;  margin_pct = net / revenue × 100 (1 dp)
 *   materialPerKg  = Σ landed total_ugx / Σ received_kg of purchases received up to `to`, else site_settings.material_cost_per_kg
 *   per product    : basis = output kg (pellets) or good units (others), over production entries carrying a product
 *                    material = kgMine × materialPerKg / basis
 *                    overhead = (Σ costs except materials) × kgMine / kgAll / basis
 *                    total = material + overhead; margin_pct = (price − total) / price × 100
 *   Rounding of the shown figures is JavaScript Math.round, as the prototype.
 */
final class CostBook
{
    public const string NAMESPACE = '6f0b2c8e-3d41-5a77-9e2b-1c4d8a5f7e90';

    public const string EXCLUDED = 'excluded';

    /** Documented defaults; anything else under cash:<category> maps to <category> itself. */
    public const array DEFAULT_MAP = [
        'cash:payout' => 'wages',
        'payout:cash' => 'wages',
        'payout:non_cash' => 'wages',
        'advance' => 'wages',
        'collector_paid' => 'materials',
        // Spare parts are costed when bought (cash "spares"); issuing them from stock would count them twice.
        'spares_issue' => self::EXCLUDED,
    ];

    public static function timezone(): string
    {
        return (string) config('tanaad.timezone', 'UTC');
    }

    /** The site's own timezone (sites.timezone), else the configured one. */
    public static function siteTimezone(string $siteId): string
    {
        $tz = DB::table('sites')->where('id', $siteId)->value('timezone');

        return is_string($tz) && $tz !== '' ? $tz : self::timezone();
    }

    public static function periodId(string $siteId, string $from, string $to): string
    {
        return Uuid::uuid5(self::NAMESPACE, "period|{$siteId}|{$from}|{$to}")->toString();
    }

    public static function mappingId(string $siteId, string $sourceKey): string
    {
        return Uuid::uuid5(self::NAMESPACE, "map|{$siteId}|{$sourceKey}")->toString();
    }

    public static function mul(string $a, string $b): string
    {
        return (string) Rounding::to(BigDecimal::of($a)->multipliedBy(BigDecimal::of($b)), 2);
    }

    /** @return array<string, string> source_key → cost category for the site (defaults overlaid with the site's map) */
    public static function mapping(string $siteId): array
    {
        $map = self::DEFAULT_MAP;
        foreach (DB::table('cost_category_map')->where('site_id', $siteId)->orderBy('source_key')->get(['source_key', 'cost_category']) as $r) {
            $map[(string) $r->source_key] = (string) $r->cost_category;
        }
        ksort($map);

        return $map;
    }

    /** @param array<string, string> $map */
    public static function categoryOf(string $sourceKey, array $map): string
    {
        return $map[$sourceKey] ?? (str_starts_with($sourceKey, 'cash:') ? substr($sourceKey, 5) : 'other');
    }

    /** @return list<string> the days in [from, to] whose cash day is closed */
    public static function closedDays(string $siteId, string $from, string $to): array
    {
        $days = [];
        foreach (DB::table('costing_closed_days')->where(['site_id' => $siteId, 'closed' => true])->whereBetween('day', [$from, $to])->orderBy('day')->pluck('day') as $d) {
            $days[] = substr((string) $d, 0, 10);
        }

        return $days;
    }

    public static function fx(string $to): ?string
    {
        $q = fn () => DB::table('fx_rates')->where(['base' => 'USD', 'quote' => 'UGX'])->whereNull('deleted_at');
        // The reference rate in force at the period end; before the first recorded rate, the first one.
        $rate = $q()->where('day', '<=', $to)->orderByDesc('day')->value('rate') ?? $q()->orderBy('day')->value('rate');

        return is_numeric($rate) ? (string) $rate : null;
    }

    /**
     * The full frozen figure set for a site over [from, to].
     *
     * @return array<string, mixed>
     */
    public static function compute(string $siteId, string $from, string $to): array
    {
        $days = self::closedDays($siteId, $from, $to);
        $map = self::mapping($siteId);
        $fx = self::fx($to);
        $span = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        // Revenue.
        $cashSales = BigDecimal::zero();
        $invoiced = BigDecimal::zero();
        $unconverted = 0;
        $rev = $days === [] ? collect() : DB::table('costing_revenue_lines')->where('site_id', $siteId)->whereIn('day', $days)->whereNull('superseded_by')->get();
        foreach ($rev as $r) {
            $ugx = $r->amount_ugx !== null ? BigDecimal::of((string) $r->amount_ugx)
                : ($r->currency === 'USD' && $fx !== null ? BigDecimal::of((string) $r->amount)->multipliedBy($fx) : null);
            if ($ugx === null) {
                $unconverted++;

                continue;
            }
            $r->source === 'invoice' ? $invoiced = $invoiced->plus($ugx) : $cashSales = $cashSales->plus($ugx);
        }
        $revenue = $cashSales->plus($invoiced);

        // Costs.
        $byCat = [];
        $cashTotal = BigDecimal::zero();
        $nonCashTotal = BigDecimal::zero();
        $excluded = BigDecimal::zero();
        $lines = $days === [] ? collect() : DB::table('costing_cost_lines')->where('site_id', $siteId)->whereIn('day', $days)->whereNull('superseded_by')->get(['source_key', 'cash', 'amount_ugx']);
        foreach ($lines as $l) {
            $amount = BigDecimal::of((string) $l->amount_ugx);
            $cat = self::categoryOf((string) $l->source_key, $map);
            if ($cat === self::EXCLUDED) {
                $excluded = $excluded->plus($amount);

                continue;
            }
            $byCat[$cat] = ($byCat[$cat] ?? BigDecimal::zero())->plus($amount);
            (bool) $l->cash ? $cashTotal = $cashTotal->plus($amount) : $nonCashTotal = $nonCashTotal->plus($amount);
        }
        ksort($byCat);
        $totalCosts = $cashTotal->plus($nonCashTotal);
        $overheadTotal = $totalCosts->minus($byCat['materials'] ?? BigDecimal::zero());
        $net = $revenue->minus($totalCosts);

        [$materialPerKg, $materialSource] = self::materialPerKg($siteId, $to);

        return [
            'from' => $from, 'to' => $to, 'currency' => 'UGX', 'fx_usd_ugx' => $fx === null ? null : (float) $fx,
            'days_in_range' => $span, 'closed_days' => $days, 'open_days' => $span - count($days),
            'revenue' => ['cash_sales' => self::m($cashSales), 'invoiced' => self::m($invoiced), 'total' => self::m($revenue), 'unconverted_lines' => $unconverted],
            'costs' => [
                'by_category' => array_map(fn (BigDecimal $v): float => self::m($v), $byCat),
                'cash' => self::m($cashTotal), 'non_cash' => self::m($nonCashTotal), 'excluded' => self::m($excluded), 'total' => self::m($totalCosts),
            ],
            'net' => self::m($net),
            'margin_pct' => $revenue->isPositive() ? Rounding::to($net->dividedBy($revenue, 12, RoundingMode::HalfUp)->multipliedBy(100), 1)->toFloat() : 0.0,
            'material' => ['per_kg' => self::m($materialPerKg), 'source' => $materialSource],
            'unit_costs' => self::unitCosts($siteId, $days, $to, $materialPerKg, $overheadTotal, $fx),
            'mapping' => $map,
        ];
    }

    /** @return array{BigDecimal, string} */
    private static function materialPerKg(string $siteId, string $to): array
    {
        $row = DB::table('costing_material_receipts')->where('site_id', $siteId)->where('day', '<=', $to)
            ->selectRaw('COALESCE(SUM(received_kg), 0) AS kg, COALESCE(SUM(total_ugx), 0) AS ugx')->first();
        $kg = BigDecimal::of((string) ($row->kg ?? '0'));
        if ($row !== null && $kg->isPositive()) {
            return [BigDecimal::of((string) $row->ugx)->dividedBy($kg, 12, RoundingMode::HalfUp), 'purchases'];
        }
        $setting = DB::table('site_settings')->where('site_id', $siteId)->value('material_cost_per_kg');

        return [BigDecimal::of(is_numeric($setting) ? (string) $setting : '0'), 'setting'];
    }

    /**
     * @param  list<string>  $days
     * @return list<array<string, mixed>>
     */
    private static function unitCosts(string $siteId, array $days, string $to, BigDecimal $materialPerKg, BigDecimal $overheadTotal, ?string $fx): array
    {
        if ($days === []) {
            return [];
        }
        $out = DB::table('costing_output_lines')->where('site_id', $siteId)->whereIn('day', $days)->whereNotNull('product_id')
            ->groupBy('product_id')->selectRaw('product_id, SUM(output_kg) AS kg, SUM(good_units) AS units')->get()->keyBy('product_id');
        $kgAll = BigDecimal::zero();
        foreach ($out as $o) {
            $kgAll = $kgAll->plus(BigDecimal::of((string) $o->kg));
        }
        if ($kgAll->isZero()) {
            $kgAll = BigDecimal::one(); // the prototype's `|| 1`
        }
        $products = DB::table('products')->where(['site_id' => $siteId, 'active' => true])->whereNull('deleted_at')
            ->whereNotIn('kind', ['roll', 'material'])->whereIn('id', $out->keys()->all())->orderBy('internal_name')->get(['id', 'internal_name', 'kind']);
        $rows = [];
        foreach ($products as $product) {
            $price = self::price((string) $product->id, $to, $fx);
            if ($price === null) {
                continue; // the prototype costs only products with a price
            }
            $o = $out->get($product->id);
            if ($o === null) {
                continue;
            }
            $kgMine = BigDecimal::of((string) $o->kg);
            $perKg = $product->kind === 'pellet';
            $basis = $perKg ? $kgMine : BigDecimal::of((string) $o->units);
            if (! $basis->isPositive() || ! $kgMine->isPositive()) {
                continue;
            }
            $material = $kgMine->multipliedBy($materialPerKg)->dividedBy($basis, 12, RoundingMode::HalfUp);
            $overhead = $overheadTotal->multipliedBy($kgMine->dividedBy($kgAll, 12, RoundingMode::HalfUp))->dividedBy($basis, 12, RoundingMode::HalfUp);
            $total = $material->plus($overhead);
            $units = self::jsRound($basis);
            if ($units <= 1) {
                continue; // Pnl.tsx shows only c.units > 1
            }
            $rows[] = [
                'product_id' => (string) $product->id, 'product' => (string) $product->internal_name, 'kind' => (string) $product->kind,
                'basis' => $perKg ? 'kg' : 'unit', 'units' => $units,
                'material' => self::jsRound($material), 'overhead' => self::jsRound($overhead), 'total' => self::jsRound($total),
                'price_ugx' => self::m($price),
                'margin_pct' => $price->isPositive() ? self::jsRound($price->minus($total)->dividedBy($price, 12, RoundingMode::HalfUp)->multipliedBy(100)) : 0,
            ];
        }

        return $rows;
    }

    /** Local-channel price in force at `to` (else any channel), USD converted at the reference rate. */
    private static function price(string $productId, string $to, ?string $fx): ?BigDecimal
    {
        $row = DB::table('prices')->where('product_id', $productId)->whereNull('deleted_at')->where('effective_from', '<=', $to)
            ->orderByRaw("CASE WHEN channel = 'local' THEN 0 ELSE 1 END")->orderByDesc('effective_from')->first(['currency', 'amount']);
        if ($row === null) {
            return null;
        }
        $amount = BigDecimal::of((string) $row->amount);
        if ($row->currency === 'UGX') {
            return $amount;
        }

        return $fx === null ? BigDecimal::zero() : $amount->multipliedBy($fx);
    }

    /** JavaScript Math.round: half rounds towards +∞. */
    public static function jsRound(BigDecimal $n): int
    {
        return $n->plus('0.5')->toScale(0, RoundingMode::Floor)->toInt();
    }

    private static function m(BigDecimal $n): float
    {
        return Rounding::money($n);
    }
}
