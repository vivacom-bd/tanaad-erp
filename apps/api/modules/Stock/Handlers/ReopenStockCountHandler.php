<?php

declare(strict_types=1);

namespace Tanaad\Stock\Handlers;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\ReopenStockCount;
use Tanaad\Stock\Support\StockBook;

/** The site manager reopens a counted zone+product day with a reason; StockProjector clears the lock. */
final class ReopenStockCountHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ReopenStockCount) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $zoneId = $p->uuid('zone_id');
        $productId = $p->uuid('product_id');
        $day = $p->date('day');
        $reason = $p->str('reason', 500);
        if (mb_strlen(trim($reason)) < 3) {
            $p->error('reason', 'must say why (at least 3 characters)');
        }
        $p->check();
        $ledger = StockBook::countLedger($zoneId, $productId);
        $locked = DB::table('day_locks')->where(['site_id' => $siteId, 'ledger' => $ledger, 'day' => $day])->whereNull('reopened_by_event')->lockForUpdate()->exists();
        if (! $locked) {
            throw Rejection::stateConflict("No locked count for that zone and product on {$day}.");
        }

        return [Event::from($ctx, 'stock.count_reopened', [
            'zone_id' => $zoneId, 'product_id' => $productId, 'day' => $day, 'ledger' => $ledger, 'reason' => trim($reason),
        ], reason: trim($reason))];
    }
}
