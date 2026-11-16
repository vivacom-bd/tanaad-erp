<?php

declare(strict_types=1);

namespace Tanaad\Spares\Support;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Rejection;

/** Limits and lookups shared by the Spares handlers. Cross-module reads go by table name, site-scoped. */
final class Spares
{
    /** Keep quantities, costs and cost × quantity inside their DECIMAL columns. */
    public const float MAX_QTY = 1e6;

    public const float MAX_COST = 1e8;

    /** Clock slack for a device that is a little ahead of the server. */
    public const int FUTURE_SLACK_SECONDS = 120;

    /** A locked part row of the site, or 404. */
    public static function lockedPart(ConnectionInterface $db, string $siteId, string $partId): \stdClass
    {
        $part = $db->table('parts')->where(['id' => $partId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();

        return $part ?? throw Rejection::notFound('Part', $partId);
    }

    /** A machine of the site that can be worked on (not retired); locked so one breakdown at a time is decided serially. */
    public static function lockedMachine(ConnectionInterface $db, string $siteId, string $machineId): \stdClass
    {
        $machine = $db->table('machines')->where(['id' => $machineId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($machine === null || $machine->status === 'retired') {
            throw Rejection::invalidPayload(['machine_id' => 'no such machine at this site']);
        }

        return $machine;
    }

    /** An optional ISO instant, defaulting to the command's; never in the future. */
    public static function instant(Payload $p, string $key, CarbonImmutable $default): CarbonImmutable
    {
        $raw = $p->optStr($key, 40);
        if ($raw === null) {
            return $default;
        }
        try {
            $at = CarbonImmutable::parse($raw)->utc();
        } catch (\Throwable) {
            $p->error($key, 'must be a date and time');

            return $default;
        }
        if ($at->greaterThan(CarbonImmutable::now()->addSeconds(self::FUTURE_SLACK_SECONDS))) {
            $p->error($key, 'must not be in the future');
        }

        return $at;
    }

    /** A quantity with at most three decimals (the column's scale). */
    public static function qty(Payload $p, string $key, bool $positive): float
    {
        $v = $p->num($key, positive: $positive);
        if (abs($v * 1000 - round($v * 1000)) > 1e-6) {
            $p->error($key, 'at most 3 decimals');
        }
        if ($v > self::MAX_QTY) {
            $p->error($key, 'must be at most '.self::MAX_QTY);
        }

        return $v;
    }

    public static function dec(float|int|string $n): string
    {
        return (string) BigDecimal::of(is_float($n) ? sprintf('%.15G', $n) : (string) $n)->toString();
    }
}
