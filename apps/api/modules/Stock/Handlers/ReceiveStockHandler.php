<?php

declare(strict_types=1);

namespace Tanaad\Stock\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\ReceiveStock;
use Tanaad\Stock\Support\StockReads;

/** Goods received into a zone without a label (a label, when printed, comes from Labels on its own). */
final class ReceiveStockHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ReceiveStock) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $receiptId = $p->optUuid('receipt_id') ?? (string) Str::uuid7();
        $productId = $p->uuid('product_id');
        $zoneId = $p->uuid('zone_id');
        $q = StockReads::quantities($p);
        $cause = $p->optStr('cause', 120);
        $reason = $p->optStr('reason', 500);
        $p->check();
        StockReads::mustBelong($p, 'products', 'product_id', $productId, $siteId);
        StockReads::mustBelong($p, 'zones', 'zone_id', $zoneId, $siteId);
        $p->check();
        StockReads::assertDayOpen($siteId, $ctx, $productId, $zoneId);

        return [Event::from($ctx, 'stock.received', [
            'receipt_id' => $receiptId,
            'product_id' => $productId, 'zone_id' => $zoneId, ...$q, 'cause' => $cause, 'reason' => $reason,
            'day' => StockReads::siteToday($siteId, $ctx),
        ])];
    }
}
