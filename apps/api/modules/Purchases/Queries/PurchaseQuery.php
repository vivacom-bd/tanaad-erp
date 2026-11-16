<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Queries;

use Illuminate\Database\ConnectionInterface;

/** Read shape (prototype camelCase) for purchases. */
final class PurchaseQuery
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array<string, mixed>> */
    public function forSite(string $siteId): array
    {
        $rows = $this->db->table('purchases')->where('site_id', $siteId)->whereNull('deleted_at')
            ->orderByDesc('ordered_on')->orderByDesc('seq')->get();

        return array_values($rows->map(fn (object $r): array => self::purchase($r))->all());
    }

    /** @return array<string, mixed> */
    public static function purchase(\stdClass $r): array
    {
        $costs = json_decode((string) $r->costs, true);

        return [
            'id' => (string) $r->id, 'siteId' => (string) $r->site_id, 'reference' => (string) $r->reference,
            'supplier' => (string) $r->supplier, 'originCountry' => (string) ($r->origin_country ?? ''), 'material' => (string) $r->material,
            'qtyKg' => (float) $r->qty_kg, 'unitPrice' => (float) $r->unit_price, 'currency' => (string) $r->currency,
            'fxReference' => $r->fx_reference === null ? null : (float) $r->fx_reference,
            'costs' => is_array($costs) ? $costs : [], 'status' => (string) $r->status,
            'orderedOn' => substr((string) $r->ordered_on, 0, 10),
            'expectedOn' => $r->expected_on === null ? null : substr((string) $r->expected_on, 0, 10),
            'receivedOn' => $r->received_on === null ? null : substr((string) $r->received_on, 0, 10),
            'receivedKg' => $r->received_kg === null ? null : (float) $r->received_kg,
            'variance' => (bool) $r->variance,
            'landed' => $r->total_ugx === null ? null : [
                'goodsUgx' => (float) $r->goods_ugx, 'extrasUgx' => (float) $r->extras_ugx, 'totalUgx' => (float) $r->total_ugx,
                'perKgUgx' => (float) $r->per_kg_ugx, 'upliftPct' => (float) $r->uplift_pct,
            ],
            'note' => $r->note,
        ];
    }
}
