<?php

declare(strict_types=1);

namespace Tanaad\Shared\Arithmetic;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Tanaad\Shared\Money\Rounding;

/**
 * One-to-one port of packages/domain/src/arithmetic.ts. Both implementations are tested
 * against contracts/fixtures/arithmetic.json; change the TypeScript and the fixtures first.
 * Inputs and outputs are plain floats/arrays with the TypeScript names so JSON matches.
 */
final class Arithmetic
{
    /** @param list<array{bundles: int|float, stdBundleWeightKg: int|float}> $lines */
    public static function expectedCargoKg(array $lines): float
    {
        $sum = BigDecimal::zero();
        foreach ($lines as $l) {
            $sum = $sum->plus(self::d($l['bundles'])->multipliedBy(self::d($l['stdBundleWeightKg'])));
        }

        return Rounding::kg($sum);
    }

    public static function variancePct(float|int $expectedKg, float|int $actualKg): float
    {
        if ($expectedKg <= 0) {
            return 0.0;
        }

        return Rounding::money(self::d($actualKg)->minus(self::d($expectedKg))->dividedBy(self::d($expectedKg), 12, RoundingMode::HalfUp)->multipliedBy(100));
    }

    /**
     * @param  array{expectedKg: float|int, actualKg: float|int, tolerancePct: float|int, scannedBundles: int, orderedBundles: int}  $a
     * @return array{decision: string, variancePct: float, reasons: list<string>}
     */
    public static function gateDecision(array $a): array
    {
        $v = self::variancePct($a['expectedKg'], $a['actualKg']);
        $reasons = [];
        if (abs($v) > $a['tolerancePct']) {
            $reasons[] = sprintf('Weight variance %s%s%% exceeds ±%s%%', $v > 0 ? '+' : '', self::num($v), self::num($a['tolerancePct']));
        }
        if ($a['scannedBundles'] !== $a['orderedBundles']) {
            $reasons[] = sprintf('Scanned %d bundles, order has %d', $a['scannedBundles'], $a['orderedBundles']);
        }

        return ['decision' => $reasons === [] ? 'pass' : 'hold', 'variancePct' => $v, 'reasons' => $reasons];
    }

    /**
     * @param  array{kgIn: float|int, kgOut: float|int, wipDeltaKg: float|int, wasteKg: float|int, allowedLossPct: float|int}  $a
     * @return array{allowedLossKg: float, gapKg: float, gapPct: float, state: string}
     */
    public static function massBalance(array $a): array
    {
        $kgIn = self::d($a['kgIn']);
        $allowedLossKg = Rounding::kg($kgIn->multipliedBy(self::d($a['allowedLossPct'])->dividedBy(100, 12, RoundingMode::HalfUp)));
        $accounted = self::d($a['kgOut'])->plus(self::d($a['wipDeltaKg']))->plus(self::d($a['wasteKg']))->plus(self::d($allowedLossKg));
        $gapKg = Rounding::kg($kgIn->minus($accounted));
        $gapPct = $kgIn->isPositive() ? Rounding::money(self::d($gapKg)->dividedBy($kgIn, 12, RoundingMode::HalfUp)->multipliedBy(100)) : 0.0;
        $state = $gapKg <= 0 ? 'balanced' : ($gapPct <= $a['allowedLossPct'] ? 'explainable' : 'missing');

        return ['allowedLossKg' => $allowedLossKg, 'gapKg' => $gapKg, 'gapPct' => $gapPct, 'state' => $state];
    }

    /**
     * @param  array{rateBasis: string, rate: float|int, unitsWorked: float|int, overtimeHours?: float|int, overtimeRatePerHour?: float|int, bonus?: float|int, advances?: float|int}  $a
     * @return array{gross: float, overtime: float, bonus: float, advances: float, net: float}
     */
    public static function settlementNet(array $a): array
    {
        $gross = Rounding::money(self::d($a['rate'])->multipliedBy(self::d($a['unitsWorked'])));
        $overtime = Rounding::money(self::d($a['overtimeHours'] ?? 0)->multipliedBy(self::d($a['overtimeRatePerHour'] ?? 0)));
        $bonus = Rounding::money($a['bonus'] ?? 0);
        $advances = Rounding::money($a['advances'] ?? 0);
        $net = Rounding::money(self::d($gross)->plus(self::d($overtime))->plus(self::d($bonus))->minus(self::d($advances)));

        return ['gross' => $gross, 'overtime' => $overtime, 'bonus' => $bonus, 'advances' => $advances, 'net' => $net];
    }

    /**
     * @param  array{opening: float|int, sales: float|int, receipts: float|int, expenses: float|int, wages: float|int, counted: float|int}  $a
     * @return array{expectedClosing: float, variance: float}
     */
    public static function cashClose(array $a): array
    {
        $expected = Rounding::money(self::d($a['opening'])->plus(self::d($a['sales']))->plus(self::d($a['receipts']))->minus(self::d($a['expenses']))->minus(self::d($a['wages'])));

        return ['expectedClosing' => $expected, 'variance' => Rounding::money(self::d($a['counted'])->minus(self::d($expected)))];
    }

    public static function contractDaysRemaining(string $endDate, string $today): int
    {
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'UTC');
        $now = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC');
        if ($end === null || $now === null) {
            throw new \InvalidArgumentException('Dates must be YYYY-MM-DD.');
        }

        return (int) round(($end->getTimestamp() - $now->getTimestamp()) / 86_400);
    }

    public static function contractState(?string $endDate, string $today, int $warningDays = 3): string
    {
        if ($endDate === null || $endDate === '') {
            return 'open';
        }
        $d = self::contractDaysRemaining($endDate, $today);
        if ($d < 0) {
            return 'expired';
        }

        return $d <= $warningDays ? 'expiring' : 'open';
    }

    public static function yieldPct(float|int $inputKg, float|int $outputKg): float
    {
        return $inputKg > 0 ? Rounding::money(self::d($outputKg)->dividedBy(self::d($inputKg), 12, RoundingMode::HalfUp)->multipliedBy(100)) : 0.0;
    }

    /**
     * @param  array{grossKg: float|int, tareKg: float|int, deductionPct: float|int, pricePerKg: float|int}  $a
     * @return array{netKg: float, amount: float}
     */
    public static function intakeNet(array $a): array
    {
        $clean = self::d($a['grossKg'])->minus(self::d($a['tareKg']));
        if ($clean->isNegative()) {
            $clean = BigDecimal::zero();
        }
        $netKg = Rounding::kg($clean->multipliedBy(BigDecimal::one()->minus(self::d($a['deductionPct'])->dividedBy(100, 12, RoundingMode::HalfUp))));

        return ['netKg' => $netKg, 'amount' => Rounding::money(self::d($netKg)->multipliedBy(self::d($a['pricePerKg'])))];
    }

    /** @return array{bundles: int, loosePieces: int} */
    public static function bundlesFromPieces(int $pieces, int $bundlePieces): array
    {
        return ['bundles' => intdiv($pieces, $bundlePieces), 'loosePieces' => $pieces % $bundlePieces];
    }

    /**
     * @param  array{qtyKg: float|int, unitPrice: float|int, currency: string, fx: float|int, costs: list<array{amount: float|int, currency: string}>, receivedKg?: float|int|null}  $a
     * @return array{goodsUgx: float, extrasUgx: float, totalUgx: float, perKgUgx: float, upliftPct: float}
     */
    public static function landedCost(array $a): array
    {
        $fx = self::d($a['fx']);
        $toUgx = fn (BigDecimal $amount, string $currency): BigDecimal => $currency === 'USD' ? $amount->multipliedBy($fx) : $amount;
        $goods = $toUgx(self::d($a['qtyKg'])->multipliedBy(self::d($a['unitPrice'])), $a['currency']);
        $extras = BigDecimal::zero();
        foreach ($a['costs'] as $c) {
            $extras = $extras->plus($toUgx(self::d($c['amount']), $c['currency']));
        }
        $received = $a['receivedKg'] ?? null;
        $kg = ($received !== null && $received > 0) ? self::d($received) : self::d($a['qtyKg']);
        $total = $goods->plus($extras);

        return [
            'goodsUgx' => Rounding::money($goods),
            'extrasUgx' => Rounding::money($extras),
            'totalUgx' => Rounding::money($total),
            'perKgUgx' => $kg->isPositive() ? Rounding::money($total->dividedBy($kg, 12, RoundingMode::HalfUp)) : 0.0,
            'upliftPct' => $goods->isPositive() ? Rounding::money($extras->dividedBy($goods, 12, RoundingMode::HalfUp)->multipliedBy(100)) : 0.0,
        ];
    }

    public static function round2(float|int $n): float
    {
        return Rounding::money($n);
    }

    public static function round3(float|int $n): float
    {
        return Rounding::kg($n);
    }

    private static function d(float|int|string $n): BigDecimal
    {
        return Rounding::to($n, 12)->strippedOfTrailingZeros();
    }

    /** Number formatting that matches JavaScript template interpolation (no trailing zeros). */
    private static function num(float|int $n): string
    {
        return rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');
    }
}
