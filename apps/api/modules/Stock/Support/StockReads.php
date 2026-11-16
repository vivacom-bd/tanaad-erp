<?php

declare(strict_types=1);

namespace Tanaad\Stock\Support;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Handle-time reads for stock handlers. Other modules' tables are read by name only and always
 * site-scoped; rows a decision rests on are locked inside the command transaction.
 */
final class StockReads
{
    public static function siteToday(string $siteId, CommandContext $ctx): string
    {
        $tz = (string) (DB::table('sites')->where('id', $siteId)->value('timezone') ?? throw Rejection::notFound('Site', $siteId));

        return $ctx->occurredAt->setTimezone($tz)->toDateString();
    }

    /**
     * A counted zone-product day is closed to stock changes until a manager reopens it (409 day_locked),
     * the same lock RecordStockCount takes. The count's own paired adjustment is emitted by the count handler.
     */
    public static function assertDayOpen(string $siteId, CommandContext $ctx, ?string $productId, ?string ...$zoneIds): void
    {
        if ($productId === null) {
            return;
        }
        $day = self::siteToday($siteId, $ctx);
        $locks = app(DayLocks::class);
        foreach (array_unique(array_filter($zoneIds, fn (?string $z): bool => $z !== null)) as $zoneId) {
            $locks->assertOpen($siteId, StockBook::countLedger($zoneId, $productId), $day);
        }
    }

    /** Products and zones must belong to the command's site (and not be deleted). */
    public static function mustBelong(Payload $p, string $table, string $key, ?string $id, string $siteId): void
    {
        if ($id === null) {
            return;
        }
        if (! DB::table($table)->where(['id' => $id, 'site_id' => $siteId])->whereNull('deleted_at')->exists()) {
            $p->error($key, 'not found at this site');
        }
    }

    /**
     * An in-stock label of the site, by id or code, locked for update.
     *
     * @return object{id: string, code: string, product_id: ?string, zone_id: ?string, qty_units: string, qty_kg: string, status: string}
     */
    public static function inStockLabel(string $siteId, ?string $id, ?string $code): object
    {
        /** @var object{id: string, code: string, product_id: ?string, zone_id: ?string, qty_units: string, qty_kg: string, status: string}|null $label */
        $label = DB::table('labels')->where('site_id', $siteId)
            ->when($id !== null, fn ($q) => $q->where('id', $id), fn ($q) => $q->where('code', $code))
            ->lockForUpdate()->first(['id', 'code', 'product_id', 'zone_id', 'qty_units', 'qty_kg', 'status']);
        if ($label === null) {
            throw Rejection::notFound('Label', $id ?? (string) $code);
        }
        if ($label->status !== 'in_stock') {
            throw Rejection::stateConflict("Label {$label->code} is {$label->status}, not in stock.");
        }
        // A label scanned onto an open dispatch (dispatch_bundles, read by table name) stays put until unscanned or gated.
        $loadedOn = DB::table('dispatch_bundles')->join('dispatches', 'dispatches.id', '=', 'dispatch_bundles.dispatch_id')
            ->where('dispatch_bundles.label_id', $label->id)->whereIn('dispatches.gate_status', ['pending', 'hold'])
            ->value('dispatches.order_ref');
        if ($loadedOn !== null) {
            throw Rejection::stateConflict("Label {$label->code} is loaded on dispatch {$loadedOn}; unscan it first.");
        }

        return $label;
    }

    /**
     * The on-hand row for (site, product, zone), locked; zeros when there is none yet.
     *
     * @return array{bundles: int, pieces: string, kg: string}
     */
    public static function onHand(string $siteId, string $productId, ?string $zoneId): array
    {
        $row = DB::table('stock_on_hand')->where('id', StockBook::onHandId($siteId, $productId, $zoneId))->lockForUpdate()->first();

        return [
            'bundles' => (int) ($row->bundles ?? 0),
            'pieces' => StockBook::qty((string) ($row->pieces ?? '0')),
            'kg' => StockBook::qty((string) ($row->kg ?? '0')),
        ];
    }

    /**
     * Signed bundles / units / kg from a payload, capped. With $signed false all must be >= 0
     * and at least one > 0.
     *
     * @return array{bundles: int, qty_units: string, qty_kg: string}
     */
    public static function quantities(Payload $p, bool $signed = false): array
    {
        $min = $signed ? null : 0.0;
        $bundles = $p->optInt('bundles', $signed ? null : 0) ?? 0;
        $units = $p->optNum('qty_units', $min) ?? 0.0;
        $kg = $p->optNum('qty_kg', $min) ?? 0.0;
        if (abs($bundles) > StockBook::MAX_BUNDLES) {
            $p->error('bundles', 'must be at most '.StockBook::MAX_BUNDLES);
        }
        foreach (['qty_units' => $units, 'qty_kg' => $kg] as $key => $q) {
            if (abs($q) > StockBook::MAX_QTY) {
                $p->error($key, 'must be at most 10000000');
            }
        }
        if ($bundles === 0 && $units == 0.0 && $kg == 0.0) {
            $p->error('qty', 'bundles, qty_units or qty_kg is required and must not be zero');
        }

        return ['bundles' => $bundles, 'qty_units' => StockBook::qty($units), 'qty_kg' => StockBook::qty($kg)];
    }

    /**
     * Rejects taking more out than is on hand (409).
     *
     * @param  array{bundles: int, pieces: string, kg: string}  $have
     * @param  array{bundles: int, qty_units: string, qty_kg: string}  $out
     */
    public static function assertCovers(array $have, array $out, string $what): void
    {
        if ($have['bundles'] < $out['bundles']
            || \Brick\Math\BigDecimal::of($have['pieces'])->isLessThan($out['qty_units'])
            || \Brick\Math\BigDecimal::of($have['kg'])->isLessThan($out['qty_kg'])) {
            throw Rejection::stateConflict("Not enough stock {$what}: {$have['bundles']} bundles, {$have['pieces']} units, {$have['kg']} kg on hand.");
        }
    }
}
