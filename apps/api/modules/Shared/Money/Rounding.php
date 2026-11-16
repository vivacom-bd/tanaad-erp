<?php

declare(strict_types=1);

namespace Tanaad\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Half-up, away from zero: the one rounding rule (ADR-009). Mirrors round2/round3 in
 * packages/domain/src/arithmetic.ts exactly; contracts/fixtures/arithmetic.json pins both.
 */
final class Rounding
{
    /** @param int<0, max> $places */
    public static function to(BigNumber|int|float|string $n, int $places): BigDecimal
    {
        return BigDecimal::of(self::normalise($n))->toScale($places, RoundingMode::HalfUp);
    }

    public static function money(BigNumber|int|float|string $n): float
    {
        return self::to($n, 2)->toFloat();
    }

    public static function kg(BigNumber|int|float|string $n): float
    {
        return self::to($n, 3)->toFloat();
    }

    /**
     * Floats come in from JSON; they are printed with 15 significant digits so that
     * 1.005 stays 1.005 and not 1.00499999999999989.
     */
    private static function normalise(BigNumber|int|float|string $n): BigNumber|int|string
    {
        if (is_float($n)) {
            if (! is_finite($n)) {
                throw new \InvalidArgumentException('Cannot round a non-finite number.');
            }

            $s = sprintf('%.15G', $n);
            if (str_contains($s, '.') && ! str_contains($s, 'E')) {
                $s = rtrim(rtrim($s, '0'), '.');
            }

            return $s === '' || $s === '-0' ? '0' : $s;
        }

        return $n;
    }
}
