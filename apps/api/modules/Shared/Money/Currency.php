<?php

declare(strict_types=1);

namespace Tanaad\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

enum Currency: string
{
    case UGX = 'UGX';
    case USD = 'USD';

    /**
     * Decimal places of the currency's minor unit (ISO 4217): UGX has none.
     *
     * @return int<0, max>
     */
    public function minorUnits(): int
    {
        return match ($this) {
            self::UGX => 0,
            self::USD => 2,
        };
    }

    /**
     * Minor units for any ISO code; unknown codes are treated as 2-decimal currencies.
     *
     * @return int<0, max>
     */
    public static function minorUnitsOf(string $code): int
    {
        return self::tryFrom(strtoupper($code))?->minorUnits() ?? 2;
    }

    /** True when the amount has no more decimal places than the currency allows. */
    public static function fits(float|int|string $amount, string $code): bool
    {
        $d = BigDecimal::of((string) $amount);

        return $d->isEqualTo($d->toScale(self::minorUnitsOf($code), RoundingMode::Down));
    }
}
