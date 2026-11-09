<?php

declare(strict_types=1);

namespace Tanaad\Cash\Queries;

use Brick\Math\BigDecimal;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Cash\Support\CashBook;

/** The cash book read shape (prototype camelCase): one day with its entries and totals, or a month by category. */
final class CashSummaryQuery
{
    /** @return array<string, mixed> */
    public function day(string $siteId, string $day): array
    {
        $cashDay = CashDay::query()->find(CashBook::dayId($siteId, $day));
        $entries = CashEntry::query()->where(['site_id' => $siteId, 'day' => $day])->orderBy('seq')->get();
        $opening = $cashDay === null ? CashBook::openingFor($siteId, $day) : CashBook::money((string) $cashDay->opening);
        $buckets = CashBook::buckets(CashBook::dayId($siteId, $day));
        $live = $entries->whereNull('superseded_by');
        $sum = fn (string $dir): float => CashBook::money(array_reduce(
            $live->where('direction', $dir)->all(), fn (BigDecimal $t, CashEntry $e): BigDecimal => $t->plus((string) $e->amount), BigDecimal::zero(),
        ));
        $expected = CashBook::money(BigDecimal::of((string) $opening)->plus((string) $buckets['sales'])->plus((string) $buckets['receipts'])
            ->minus((string) $buckets['expenses'])->minus((string) $buckets['wages']));

        return [
            'day' => $cashDay === null ? null : self::dayShape($cashDay),
            'date' => $day,
            'entries' => array_values($entries->map(fn (CashEntry $e): array => self::entry($e))->all()),
            'totals' => [
                'opening' => $opening, ...$buckets, 'in' => $sum('in'), 'out' => $sum('out'), 'expectedClosing' => $expected,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function month(string $siteId, string $month): array
    {
        $rows = CashEntry::query()->where('site_id', $siteId)->whereNull('superseded_by')
            ->where('day', '>=', "{$month}-01")->where('day', '<=', "{$month}-31")
            ->orderBy('category')->get(['direction', 'category', 'amount']);
        $by = [];
        $totals = ['in' => BigDecimal::zero(), 'out' => BigDecimal::zero()];
        foreach ($rows as $e) {
            $key = "{$e->direction}|{$e->category}";
            $by[$key] ??= ['direction' => $e->direction, 'category' => $e->category, 'total' => BigDecimal::zero(), 'count' => 0];
            $by[$key]['total'] = $by[$key]['total']->plus((string) $e->amount);
            $by[$key]['count']++;
            $totals[$e->direction === 'in' ? 'in' : 'out'] = $totals[$e->direction === 'in' ? 'in' : 'out']->plus((string) $e->amount);
        }

        return [
            'month' => $month,
            'byCategory' => array_values(array_map(fn (array $r): array => [...$r, 'total' => CashBook::money($r['total'])], $by)),
            'in' => CashBook::money($totals['in']),
            'out' => CashBook::money($totals['out']),
        ];
    }

    /** @return array<string, mixed> */
    public static function dayShape(CashDay $d): array
    {
        $num = fn (?string $v): ?float => $v === null ? null : CashBook::money($v);

        return [
            'id' => $d->id, 'siteId' => $d->site_id, 'day' => $d->dayString(), 'currency' => $d->currency,
            'opening' => CashBook::money((string) $d->opening), 'status' => $d->status,
            'expectedClosing' => $num($d->expected_closing), 'countedClosing' => $num($d->counted_closing), 'variance' => $num($d->variance),
            'denominations' => $d->denominations, 'signedBy' => $d->signed_by, 'closedAt' => $d->closed_at?->toIso8601String(),
            'reopenReason' => $d->reopen_reason,
        ];
    }

    /** @return array<string, mixed> */
    public static function entry(CashEntry $e): array
    {
        return [
            'id' => $e->id, 'cashDayId' => $e->cash_day_id, 'siteId' => $e->site_id, 'day' => $e->dayString(),
            'direction' => $e->direction, 'category' => $e->category, 'subcategory' => $e->subcategory,
            'amount' => CashBook::money((string) $e->amount), 'currency' => $e->currency,
            'fxReference' => $e->fx_reference === null ? null : (float) $e->fx_reference,
            'machineId' => $e->machine_id, 'productId' => $e->product_id, 'payee' => $e->payee, 'note' => $e->note,
            'hasReceiptPhoto' => $e->has_receipt_photo, 'payoutId' => $e->payout_id,
            'correctsEntryId' => $e->corrects_entry_id, 'supersededBy' => $e->superseded_by,
            'at' => $e->at->toIso8601String(), 'by' => $e->recorded_by,
        ];
    }
}
