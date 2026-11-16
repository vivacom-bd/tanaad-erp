<?php

declare(strict_types=1);

namespace Tanaad\Stock\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Support\StockBook;

/** Stock read shapes in the prototype's camelCase (selectors.ts stockByProduct, StockMovement, StockCount). */
final class StockQuery
{
    public const array KINDS = ['production_in', 'consumed', 'dispatch_out', 'receive', 'transfer', 'label_move', 'adjust', 'scrap', 'transfer_out', 'transfer_in'];

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * On hand per product with its zones, most bundles first; empty rows left out.
     *
     * @return list<array<string, mixed>>
     */
    public function onHand(string $siteId): array
    {
        $rows = $this->db->table('stock_on_hand')->where('site_id', $siteId)
            ->where(fn ($q) => $q->where('bundles', '!=', 0)->orWhere('pieces', '!=', 0)->orWhere('kg', '!=', 0))
            ->orderBy('product_id')->orderBy('zone_id')->get();
        $products = $this->db->table('products')->whereIn('id', $rows->pluck('product_id')->unique()->all())->get()->keyBy('id');
        $zones = $this->db->table('zones')->whereIn('id', $rows->pluck('zone_id')->filter()->unique()->all())->pluck('name', 'id');
        $by = [];
        foreach ($rows as $r) {
            $pid = (string) $r->product_id;
            $by[$pid] ??= ['bundles' => 0, 'pieces' => \Brick\Math\BigDecimal::zero(), 'kg' => \Brick\Math\BigDecimal::zero(), 'zones' => []];
            $by[$pid]['bundles'] += (int) $r->bundles;
            $by[$pid]['pieces'] = $by[$pid]['pieces']->plus((string) $r->pieces);
            $by[$pid]['kg'] = $by[$pid]['kg']->plus((string) $r->kg);
            $by[$pid]['zones'][] = [
                'zoneId' => $r->zone_id, 'zoneName' => $r->zone_id === null ? null : ($zones[$r->zone_id] ?? null),
                'bundles' => (int) $r->bundles, 'pieces' => StockBook::num((string) $r->pieces), 'kg' => StockBook::num((string) $r->kg),
            ];
        }
        $out = [];
        foreach ($by as $pid => $row) {
            $prod = $products[$pid] ?? null;
            $out[] = [
                'product' => ['id' => $pid, 'code' => $prod->code ?? null, 'name' => $prod === null ? null : ($prod->commercial_name ?? $prod->internal_name)],
                'bundles' => $row['bundles'], 'pieces' => StockBook::num((string) $row['pieces']), 'kg' => StockBook::num((string) $row['kg']),
                'zones' => $row['zones'],
            ];
        }
        usort($out, fn (array $a, array $b): int => $b['bundles'] <=> $a['bundles']);

        return $out;
    }

    /** @return array<string, mixed> */
    public function movements(string $siteId, ?string $kind, ?string $day, string $tz, int $page, int $perPage): array
    {
        if ($kind !== null && ! in_array($kind, self::KINDS, true)) {
            throw Rejection::invalidPayload(['kind' => 'must be one of: '.implode(', ', self::KINDS)]);
        }
        $q = $this->db->table('stock_movements')->where('site_id', $siteId)->when($kind !== null, fn ($q) => $q->where('kind', $kind));
        if ($day !== null) {
            self::assertDay($day);
            $start = CarbonImmutable::parse($day, $tz)->startOfDay()->utc();
            $q->whereBetween('at', [$start, $start->addDay()->subMicrosecond()]);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('seq')->forPage($page, $perPage)->get();

        return [
            'data' => array_values($rows->map(fn (\stdClass $m): array => [
                'id' => $m->id, 'siteId' => $m->site_id, 'kind' => $m->kind, 'labelId' => $m->label_id, 'labelCode' => $m->label_code,
                'productId' => $m->product_id, 'fromZoneId' => $m->from_zone_id, 'toZoneId' => $m->to_zone_id,
                'fromSiteId' => $m->from_site_id, 'toSiteId' => $m->to_site_id, 'transferId' => $m->transfer_id, 'countId' => $m->count_id,
                'bundles' => (int) $m->bundles, 'qtyUnits' => StockBook::num((string) $m->qty_units), 'qtyKg' => StockBook::num((string) $m->qty_kg),
                'reason' => $m->reason, 'cause' => $m->cause, 'by' => $m->by, 'at' => CarbonImmutable::parse($m->at)->toIso8601String(),
            ])->all()),
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
        ];
    }

    /** @return array<string, mixed> counts of the day with their variance; the latest count per zone+product is "current" */
    public function variance(string $siteId, string $day): array
    {
        self::assertDay($day);
        $rows = $this->db->table('stock_counts')->where(['site_id' => $siteId, 'day' => $day])->orderBy('at')->orderBy('id')->get();
        $latest = [];
        foreach ($rows as $r) {
            $latest[$r->zone_id.'|'.$r->product_id] = $r->id;
        }
        $counts = array_values($rows->map(fn (\stdClass $r): array => [
            'id' => $r->id, 'siteId' => $r->site_id, 'zoneId' => $r->zone_id, 'productId' => $r->product_id, 'day' => substr((string) $r->day, 0, 10),
            'countedBundles' => (int) $r->counted_bundles, 'systemBundles' => (int) $r->system_bundles, 'variance' => (int) $r->variance,
            'reason' => $r->reason, 'status' => $r->status, 'reopenReason' => $r->reopen_reason, 'current' => $latest[$r->zone_id.'|'.$r->product_id] === $r->id,
            'by' => $r->by, 'at' => CarbonImmutable::parse($r->at)->toIso8601String(),
        ])->all());
        $current = array_values(array_filter($counts, fn (array $c): bool => $c['current']));

        return [
            'day' => $day, 'counts' => $counts,
            'totals' => [
                'counted' => count($current),
                'withVariance' => count(array_filter($current, fn (array $c): bool => $c['variance'] !== 0)),
                'netBundles' => array_sum(array_column($current, 'variance')),
                'absBundles' => array_sum(array_map(fn (array $c): int => abs($c['variance']), $current)),
            ],
        ];
    }

    /** @return list<array<string, mixed>> transfers sent from or to the site, newest first */
    public function transfers(string $siteId): array
    {
        $rows = $this->db->table('stock_transfers')->where('site_id', $siteId)->orWhere('to_site_id', $siteId)->orderByDesc('seq')->limit(500)->get();

        return array_values($rows->map(fn (\stdClass $t): array => self::transfer($t))->all());
    }

    /** @return array<string, mixed> */
    public static function transfer(\stdClass $t): array
    {
        $n = fn (mixed $v): ?float => $v === null ? null : StockBook::num((string) $v);

        return [
            'id' => $t->id, 'fromSiteId' => $t->site_id, 'toSiteId' => $t->to_site_id, 'productId' => $t->product_id, 'receivedProductId' => $t->received_product_id,
            'fromZoneId' => $t->from_zone_id, 'toZoneId' => $t->to_zone_id, 'status' => $t->status,
            'bundles' => (int) $t->bundles, 'qtyUnits' => $n($t->qty_units), 'qtyKg' => $n($t->qty_kg),
            'receivedBundles' => $t->received_bundles === null ? null : (int) $t->received_bundles, 'receivedUnits' => $n($t->received_units), 'receivedKg' => $n($t->received_kg),
            'discrepancyBundles' => $t->discrepancy_bundles === null ? null : (int) $t->discrepancy_bundles,
            'discrepancyUnits' => $n($t->discrepancy_units), 'discrepancyKg' => $n($t->discrepancy_kg),
            'note' => $t->note, 'receiveNote' => $t->receive_note, 'sentBy' => $t->sent_by, 'receivedBy' => $t->received_by,
            'sentAt' => CarbonImmutable::parse($t->sent_at)->toIso8601String(),
            'receivedAt' => $t->received_at === null ? null : CarbonImmutable::parse($t->received_at)->toIso8601String(),
        ];
    }

    private static function assertDay(string $day): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            throw Rejection::invalidPayload(['day' => 'must be a date (YYYY-MM-DD)']);
        }
    }
}
