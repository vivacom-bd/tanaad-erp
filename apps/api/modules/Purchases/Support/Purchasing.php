<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Support;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Calendar\Calendar;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/** Helpers shared by the Purchases handlers, projector and queries. Cross-module reads go by table name. */
final class Purchasing
{
    /** Number series kind for purchase orders, defined per site through Config (PO-0001 style). */
    public const string SERIES = 'purchase_order';

    public const float MAX_KG = 1e7;

    public const float MAX_PRICE = 1e9;

    public const float MAX_COST = 1e12;

    /** Below this share of the ordered kg a receipt is flagged as a variance (prototype: qty * 0.995). */
    public const string VARIANCE_FLOOR = '0.995';

    /** Business day of an instant in the site's calendar (ADR-008). */
    public static function businessDay(string $siteId, CarbonImmutable $at): string
    {
        $site = DB::table('sites')->where('id', $siteId)->first(['code', 'shifts', 'timezone']);
        if ($site === null) {
            throw Rejection::notFound('Site', $siteId);
        }
        $decoded = json_decode((string) ($site->shifts ?? '[]'), true);
        $shifts = [];
        foreach (is_array($decoded) ? $decoded : [] as $s) {
            if (is_array($s) && is_string($s['kind'] ?? null) && is_string($s['starts'] ?? null) && is_string($s['ends'] ?? null)) {
                $shifts[] = ['kind' => $s['kind'], 'starts' => $s['starts'], 'ends' => $s['ends']];
            }
        }

        return Calendar::forSite((string) $site->code, $shifts, (string) $site->timezone)->businessDay($at);
    }

    /** The latest USD to UGX reference from Config (group-wide), or null when none is set. */
    public static function latestFx(): ?float
    {
        $rate = DB::table('fx_rates')->where(['base' => 'USD', 'quote' => 'UGX'])->whereNull('deleted_at')
            ->orderByDesc('day')->orderByDesc('seq')->value('rate');

        return $rate === null ? null : (float) $rate;
    }

    /** True when the received kg fall short of the ordered kg by more than the tolerance. */
    public static function isVariance(float $orderedKg, float $receivedKg): bool
    {
        return Rounding::to($receivedKg, 6)->isLessThan(Rounding::to($orderedKg, 6)->multipliedBy(BigDecimal::of(self::VARIANCE_FLOOR)));
    }

    /**
     * Reads the fields an order or an edit carries, validated.
     *
     * @return array{supplier: string, origin_country: ?string, material: string, qty_kg: float, unit_price: float, currency: string, costs: list<array{label: string, amount: float, currency: string}>, expected_on: ?string, note: ?string}
     */
    public static function fields(Payload $p): array
    {
        $qty = $p->num('qty_kg', positive: true);
        if ($qty > self::MAX_KG) {
            $p->error('qty_kg', 'must be at most 10000000');
        }
        $price = $p->num('unit_price', positive: true);
        if ($price > self::MAX_PRICE) {
            $p->error('unit_price', 'must be at most 1000000000');
        }
        $currency = $p->oneOf('currency', ['UGX', 'USD']);
        $costs = [];
        foreach ($p->children('costs') as $c) {
            $amount = $c->num('amount', positive: true);
            if ($amount > self::MAX_COST) {
                $c->error('amount', 'is too large');
            }
            $costs[] = ['label' => $c->str('label', 80), 'amount' => $amount, 'currency' => $c->oneOf('currency', ['UGX', 'USD'])];
        }
        if (count($costs) > 20) {
            $p->error('costs', 'at most 20 cost lines');
        }

        return [
            'supplier' => $p->str('supplier', 160),
            'origin_country' => $p->optStr('origin_country', 80),
            'material' => $p->str('material', 160),
            'qty_kg' => $qty,
            'unit_price' => $price,
            'currency' => $currency,
            'costs' => $costs,
            'expected_on' => $p->optDate('expected_on'),
            'note' => $p->optStr('note', 1000),
        ];
    }
}
