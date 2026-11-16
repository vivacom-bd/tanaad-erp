<?php

declare(strict_types=1);

namespace Tanaad\Orders\Support;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tanaad\Shared\Calendar\Calendar;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/** Helpers shared by the Orders handlers, projector and queries. Cross-module reads go by table name. */
final class Sales
{
    /** Order statuses that commit the client's credit until the order is invoiced. */
    public const array EXPOSED_STATUSES = ['awaiting_approval', 'held', 'approved', 'picking', 'dispatched', 'delivered'];

    /** Number series kinds, defined per site through Config (config.number_series_defined). */
    public const string ORDER_SERIES = 'sales_order';

    public const string INVOICE_SERIES = 'invoice';

    public const string CASH_LEDGER = 'cash';

    public const array CHANNELS = ['local', 'export'];

    /** Forward order of the lifecycle; reactions never move an order backwards. */
    public const array RANK = [
        'awaiting_cash' => 1, 'awaiting_approval' => 1, 'held' => 1, 'approved' => 3, 'picking' => 4,
        'dispatched' => 5, 'delivered' => 6, 'invoiced' => 7, 'paid' => 8,
    ];

    /** Orders that may be loaded, invoiced or handed to Dispatch. */
    public const array RELEASED = ['approved', 'picking', 'dispatched', 'delivered'];

    public const float MAX_MONEY = 99_999_999_999_999.0;

    public const int MAX_BUNDLES = 1_000_000;

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

    /**
     * Minor-unit places of a currency: whole shillings for UGX, cents otherwise.
     *
     * @return int<0, 2>
     */
    public static function places(string $currency): int
    {
        return $currency === 'UGX' ? 0 : 2;
    }

    /** A money amount in a currency, half-up to its places. */
    public static function money(BigDecimal|float|int|string $n, string $currency): BigDecimal
    {
        return Rounding::to($n, self::places($currency));
    }

    public static function num(BigDecimal $n): float
    {
        return $n->toFloat();
    }

    /**
     * A status reached by a dispatch reaction, or after invoicing/payment: once an order is invoiced,
     * loading or delivering it shows it as invoiced (or paid), as the prototype does.
     */
    public static function settled(string $target, bool $invoiced, bool $paid): string
    {
        if (in_array($target, ['dispatched', 'delivered'], true) && $invoiced) {
            return $paid ? 'paid' : 'invoiced';
        }

        return $target;
    }

    public static function forward(string $current, string $target): bool
    {
        return (self::RANK[$target] ?? 0) > (self::RANK[$current] ?? 0);
    }
}
