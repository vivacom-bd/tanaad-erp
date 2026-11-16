<?php

declare(strict_types=1);

namespace Tanaad\Stock\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Stock\Commands\SendTransfer;
use Tanaad\Stock\Support\StockReads;

/** The sending site books a quantity out to another site; the receiver confirms it in. */
final class SendTransferHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof SendTransfer) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $transferId = $p->optUuid('transfer_id') ?? (string) Str::uuid7();
        $toSite = $p->str('to_site_id', 64);
        $productId = $p->uuid('product_id');
        $from = $p->uuid('from_zone_id');
        $q = StockReads::quantities($p);
        $note = $p->optStr('note', 500);
        $p->check();
        $toSiteId = DB::table('sites')->where('id', $toSite)->orWhere('code', $toSite)->value('id');
        if (! is_string($toSiteId)) {
            $p->error('to_site_id', 'not found');
        } elseif ($toSiteId === $siteId) {
            $p->error('to_site_id', 'must be another site');
        }
        StockReads::mustBelong($p, 'products', 'product_id', $productId, $siteId);
        StockReads::mustBelong($p, 'zones', 'from_zone_id', $from, $siteId);
        $p->check();
        if (DB::table('stock_transfers')->where('id', $transferId)->exists()) {
            throw Rejection::duplicateFact("Transfer {$transferId} already exists.");
        }
        StockReads::assertDayOpen($siteId, $ctx, $productId, $from);
        StockReads::assertCovers(StockReads::onHand($siteId, $productId, $from), $q, 'in that zone');

        return [Event::from($ctx, 'stock.transfer_sent', [
            'transfer_id' => $transferId, 'to_site_id' => $toSiteId, 'product_id' => $productId, 'from_zone_id' => $from, ...$q, 'note' => $note,
        ])];
    }
}
