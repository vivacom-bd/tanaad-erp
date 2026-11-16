<?php

declare(strict_types=1);

namespace Tanaad\Stock\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Stock vocabulary, keys and exact quantity handling (3 decimals, no float drift). */
final class StockBook
{
    public const string LEDGER = 'stock';

    public const float MAX_QTY = 1e7;

    public const int MAX_BUNDLES = 1_000_000;

    public static function qty(float|int|string $v): string
    {
        return BigDecimal::of((string) $v)->toScale(3, RoundingMode::HalfUp)->__toString();
    }

    public static function num(float|int|string|null $v): float
    {
        return (float) self::qty($v ?? 0);
    }

    public static function neg(string $v): string
    {
        return BigDecimal::of($v)->negated()->toScale(3)->__toString();
    }

    public static function minus(string $a, string $b): string
    {
        return BigDecimal::of($a)->minus($b)->toScale(3)->__toString();
    }

    public static function times(string $a, int $n): string
    {
        return BigDecimal::of($a)->multipliedBy($n)->toScale(3, RoundingMode::HalfUp)->__toString();
    }

    public static function onHandId(string $siteId, string $productId, ?string $zoneId): string
    {
        return $siteId.'|'.$productId.'|'.($zoneId ?? 'none');
    }

    /** day_locks.ledger is 32 chars: one lock per zone and product, "stock:" + 26 hex chars. */
    public static function countLedger(string $zoneId, string $productId): string
    {
        return self::LEDGER.':'.substr(hash('sha256', $zoneId.'|'.$productId), 0, 26);
    }
}
