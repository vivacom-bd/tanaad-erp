<?php

declare(strict_types=1);

namespace Tanaad\Cash\Support;

use Brick\Math\BigDecimal;
use Ramsey\Uuid\Uuid;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Money\Rounding;

/** Read-side helpers shared by handlers, the projector and the summary query. */
final class CashBook
{
    public const string LEDGER = 'cash';

    /** Categories cashClose treats as sales and as wages; every other code is a receipt or an expense by direction. */
    public const string SALE = 'sale';

    /** @var list<string> */
    public const array WAGE_CATEGORIES = ['wages', 'payout'];

    private const string NAMESPACE = '0193f6a2-6c1e-7c3a-9a55-6361736864a7';

    /** One cash day per site and business day; deterministic so the HTTP, sync and payout paths agree on replay. */
    public static function dayId(string $siteId, string $day): string
    {
        return Uuid::uuid5(self::NAMESPACE, "{$siteId}|{$day}")->toString();
    }

    public static function money(\Brick\Math\BigNumber|float|int|string $n): float
    {
        return Rounding::money($n);
    }

    /** Opening balance: the counted cash of the latest earlier day that was counted, 0 if none. */
    public static function openingFor(string $siteId, string $day): float
    {
        $prev = CashDay::query()->where('site_id', $siteId)->where('day', '<', $day)->whereNotNull('counted_closing')
            ->orderByDesc('day')->value('counted_closing');

        return self::money($prev ?? 0);
    }

    /**
     * Live entries of a day bucketed for cashClose.
     *
     * @return array{sales: float, receipts: float, expenses: float, wages: float}
     */
    public static function buckets(string $cashDayId): array
    {
        $b = ['sales' => BigDecimal::zero(), 'receipts' => BigDecimal::zero(), 'expenses' => BigDecimal::zero(), 'wages' => BigDecimal::zero()];
        $rows = CashEntry::query()->where('cash_day_id', $cashDayId)->whereNull('superseded_by')->get(['direction', 'category', 'amount']);
        foreach ($rows as $e) {
            $key = match (true) {
                $e->category === self::SALE => 'sales',
                in_array($e->category, self::WAGE_CATEGORIES, true) => 'wages',
                $e->direction === 'in' => 'receipts',
                default => 'expenses',
            };
            $b[$key] = $b[$key]->plus(BigDecimal::of((string) $e->amount));
        }

        return array_map(fn (BigDecimal $v): float => self::money($v), $b);
    }

    /** @return array{expectedClosing: float, variance: float} */
    public static function close(float $opening, string $cashDayId, float $counted): array
    {
        return Arithmetic::cashClose([...self::buckets($cashDayId), 'opening' => $opening, 'counted' => $counted]);
    }
}
