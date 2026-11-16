<?php

declare(strict_types=1);

namespace Tanaad\Stock\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\RecordStockCount;
use Tanaad\Stock\Support\StockBook;
use Tanaad\Stock\Support\StockReads;

/**
 * A storekeeper's count of one product in one zone. The system figure is read here (zone and
 * on-hand rows locked) and travels in stock.counted; a non-zero variance needs a reason and
 * emits a paired stock.adjusted (units and kg from the product's bundle standard). The
 * zone+product day is then locked in the stock ledger until a site manager reopens it.
 */
final class RecordStockCountHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordStockCount) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $countId = $p->optUuid('count_id') ?? (string) Str::uuid7();
        $zoneId = $p->uuid('zone_id');
        $productId = $p->uuid('product_id');
        $counted = $p->int('counted_bundles');
        if ($counted > StockBook::MAX_BUNDLES) {
            $p->error('counted_bundles', 'must be at most '.StockBook::MAX_BUNDLES);
        }
        $today = StockReads::siteToday($siteId, $ctx);
        $day = $p->optDate('day') ?? $today;
        if ($day > $today) {
            $p->error('day', 'must not be in the future');
        }
        $reason = $p->optStr('reason', 500);
        $p->check();
        StockReads::mustBelong($p, 'products', 'product_id', $productId, $siteId);
        StockReads::mustBelong($p, 'zones', 'zone_id', $zoneId, $siteId);
        $p->check();

        DB::table('zones')->where('id', $zoneId)->lockForUpdate()->first(['id']);
        $ledger = StockBook::countLedger($zoneId, $productId);
        $this->locks->assertOpen($siteId, $ledger, $day);
        if (DB::table('stock_counts')->where('id', $countId)->exists()) {
            throw Rejection::duplicateFact("Stock count {$countId} already exists.");
        }
        $system = StockReads::onHand($siteId, $productId, $zoneId)['bundles'];
        $variance = $counted - $system;
        if ($variance !== 0 && ($reason === null || mb_strlen(trim($reason)) < 3)) {
            throw Rejection::invalidPayload(['reason' => 'required when the count differs from the system (at least 3 characters)']);
        }

        $events = [Event::from($ctx, 'stock.counted', [
            'count_id' => $countId, 'zone_id' => $zoneId, 'product_id' => $productId, 'day' => $day,
            'counted_bundles' => $counted, 'system_bundles' => $system, 'variance' => $variance, 'reason' => $reason, 'ledger' => $ledger,
        ])];
        if ($variance !== 0) {
            $std = DB::table('products')->where('id', $productId)->first(['bundle_pieces', 'std_bundle_weight_kg']);
            $events[] = Event::from($ctx, 'stock.adjusted', [
                'adjust_id' => (string) Str::uuid7(), 'product_id' => $productId, 'zone_id' => $zoneId, 'bundles' => $variance,
                'qty_units' => StockBook::times(StockBook::qty((string) ($std->bundle_pieces ?? 0)), $variance),
                'qty_kg' => StockBook::times(StockBook::qty((string) ($std->std_bundle_weight_kg ?? 0)), $variance),
                'reason' => trim((string) $reason), 'cause' => 'count', 'count_id' => $countId,
            ], primary: false, reason: trim((string) $reason));
        }

        return $events;
    }
}
