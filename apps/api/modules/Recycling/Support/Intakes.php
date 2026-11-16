<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Support;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tanaad\Shared\Calendar\Calendar;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/** Helpers shared by the Recycling handlers, projector and queries. Cross-module reads go by table name. */
final class Intakes
{
    /** The number series kind for intake vouchers, defined per site through Config (config.number_series_defined). */
    public const string VOUCHER_SERIES = 'intake_voucher';

    public const string CASH_LEDGER = 'cash';

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

    /** What a collector is paid for a delivery: net kg × price, whole shillings, half-up (as the prototype's Math.round). */
    public static function payAmount(string $netKg, string $pricePerKg): float
    {
        return Rounding::to(BigDecimal::of($netKg)->multipliedBy(BigDecimal::of($pricePerKg)), 0)->toFloat();
    }

    /** A decimal as the prototype prints it: no trailing zeros ("1067.2", "900"). */
    public static function plain(float|int|string $n): string
    {
        return (string) Rounding::to($n, 6)->strippedOfTrailingZeros();
    }
}
