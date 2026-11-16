<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Queries;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Money\Rounding;

/** Read shapes (prototype camelCase): a day's weigh-ins with totals, and the per-collector ledger. */
final class CollectorQuery
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array<string, mixed> */
    public function day(string $siteId, string $day): array
    {
        $rows = $this->db->table('collector_deliveries')->where(['site_id' => $siteId, 'day' => $day])->whereNull('deleted_at')
            ->orderBy('seq')->get();
        $sum = fn (string $col, ?bool $paid = null): BigDecimal => array_reduce(
            $rows->filter(fn (object $r): bool => $paid === null || (bool) $r->paid === $paid)->all(),
            fn (BigDecimal $t, object $r): BigDecimal => $t->plus((string) ($r->{$col} ?? '0')),
            BigDecimal::zero(),
        );

        return [
            'date' => $day,
            'deliveries' => array_values($rows->map(fn (object $r): array => self::delivery($r))->all()),
            'totals' => [
                'deliveries' => $rows->count(),
                'grossKg' => Rounding::kg($sum('gross_kg')),
                'tareKg' => Rounding::kg($sum('tare_kg')),
                'netKg' => Rounding::kg($sum('net_kg')),
                'amount' => Rounding::money($sum('amount')),
                'paid' => Rounding::money($sum('paid_amount', true)),
                'unpaid' => Rounding::money($sum('amount', false)),
            ],
        ];
    }

    /**
     * Per collector: owed is the sum of delivery amounts; paid is cash actually paid; the balance
     * is what is still owed on unpaid deliveries (a paid delivery is settled in full, its whole-shilling
     * rounding included).
     *
     * @return list<array<string, mixed>>
     */
    public function ledger(string $siteId): array
    {
        $rows = $this->db->table('collector_deliveries')->where('site_id', $siteId)->whereNull('deleted_at')
            ->groupBy('collector')->orderBy('collector')
            ->selectRaw('collector, COUNT(*) AS deliveries, SUM(net_kg) AS net_kg, SUM(amount) AS owed,
                COALESCE(SUM(CASE WHEN paid THEN paid_amount END), 0) AS paid,
                COALESCE(SUM(CASE WHEN paid THEN 0 ELSE amount END), 0) AS balance,
                SUM(CASE WHEN paid THEN 0 ELSE 1 END) AS unpaid_deliveries, MAX(day) AS last_day')
            ->get();

        return array_values($rows->map(fn (object $r): array => [
            'collector' => (string) $r->collector,
            'deliveries' => (int) $r->deliveries,
            'unpaidDeliveries' => (int) $r->unpaid_deliveries,
            'totalNetKg' => Rounding::kg((string) $r->net_kg),
            'totalOwed' => Rounding::money((string) $r->owed),
            'totalPaid' => Rounding::money((string) $r->paid),
            'balance' => Rounding::money((string) $r->balance),
            'lastDelivery' => substr((string) $r->last_day, 0, 10),
        ])->all());
    }

    /** @return array<string, mixed> */
    public static function delivery(\stdClass $r): array
    {
        return [
            'id' => (string) $r->id, 'siteId' => (string) $r->site_id, 'day' => substr((string) $r->day, 0, 10),
            'collector' => (string) $r->collector, 'grossKg' => (float) $r->gross_kg, 'tareKg' => (float) $r->tare_kg,
            'deductionPct' => (float) $r->deduction_pct, 'deductionPresetId' => $r->deduction_preset_id,
            'netKg' => (float) $r->net_kg, 'pricePerKg' => (float) $r->price_per_kg, 'amount' => (float) $r->amount,
            'currency' => (string) $r->currency, 'hasScalePhoto' => (bool) $r->has_scale_photo, 'scalePhotoHash' => $r->scale_photo_hash,
            'voucherNo' => (string) $r->voucher_no, 'provisionalNo' => $r->provisional_no,
            'paid' => (bool) $r->paid, 'paidAt' => $r->paid_at, 'paidDay' => $r->paid_day === null ? null : substr((string) $r->paid_day, 0, 10),
            'paidAmount' => $r->paid_amount === null ? null : (float) $r->paid_amount, 'paymentId' => $r->payment_id,
            'at' => (string) $r->at,
        ];
    }
}
