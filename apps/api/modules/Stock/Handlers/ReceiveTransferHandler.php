<?php

declare(strict_types=1);

namespace Tanaad\Stock\Handlers;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\ReceiveTransfer;
use Tanaad\Stock\Support\StockBook;
use Tanaad\Stock\Support\StockReads;

/**
 * The destination site confirms a sent transfer (locked) into one of its zones. What arrived may
 * differ from what was sent; the discrepancy (sent - received) travels in the event.
 */
final class ReceiveTransferHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ReceiveTransfer) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $transferId = $p->uuid('transfer_id');
        $toZone = $p->uuid('to_zone_id');
        $productId = $p->uuid('product_id');   // the receiving site's own product; no cross-site code mapping
        $note = $p->optStr('note', 500);
        $given = $p->has('bundles') || $p->has('qty_units') || $p->has('qty_kg');
        $got = ['bundles' => $p->optInt('bundles') ?? 0, 'qty_units' => StockBook::qty($p->optNum('qty_units') ?? 0), 'qty_kg' => StockBook::qty($p->optNum('qty_kg') ?? 0)];
        foreach (['qty_units', 'qty_kg'] as $k) {
            if ((float) $got[$k] > StockBook::MAX_QTY) {
                $p->error($k, 'must be at most 10000000');
            }
        }
        $p->check();
        StockReads::mustBelong($p, 'zones', 'to_zone_id', $toZone, $siteId);
        $p->check();
        $t = DB::table('stock_transfers')->where('id', $transferId)->lockForUpdate()->first() ?? throw Rejection::notFound('Transfer', $transferId);
        if ($t->to_site_id !== $siteId) {
            throw Rejection::forbidden('Only the destination site may receive this transfer.');
        }
        if ($t->status !== 'sent') {
            throw Rejection::stateConflict("Transfer {$transferId} is already {$t->status}.");
        }
        StockReads::mustBelong($p, 'products', 'product_id', $productId, $siteId);
        $p->check();
        StockReads::assertDayOpen($siteId, $ctx, $productId, $toZone);
        $sent = ['bundles' => (int) $t->bundles, 'qty_units' => StockBook::qty((string) $t->qty_units), 'qty_kg' => StockBook::qty((string) $t->qty_kg)];
        if (! $given) {
            $got = $sent;
        }

        return [Event::from($ctx, 'stock.transfer_received', [
            'transfer_id' => $transferId, 'from_site_id' => $t->site_id, 'product_id' => $productId, 'sent_product_id' => $t->product_id, 'to_zone_id' => $toZone,
            ...$got,
            'sent_bundles' => $sent['bundles'], 'sent_units' => $sent['qty_units'], 'sent_kg' => $sent['qty_kg'],
            'discrepancy_bundles' => $sent['bundles'] - $got['bundles'],
            'discrepancy_units' => StockBook::minus($sent['qty_units'], $got['qty_units']),
            'discrepancy_kg' => StockBook::minus($sent['qty_kg'], $got['qty_kg']),
            'note' => $note,
        ])];
    }
}
