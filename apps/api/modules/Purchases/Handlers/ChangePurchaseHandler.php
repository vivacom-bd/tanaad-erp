<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Handlers;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Purchases\Commands\ChangePurchase;
use Tanaad\Purchases\Support\Purchasing;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Edits a purchase (fields, or moving it ordered -> in transit -> at the border) before it is
 * received. The event carries the full new state, so the projector only overwrites. The
 * reference and the captured FX reference stay; FX is captured now only if the order had none.
 */
final class ChangePurchaseHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ChangePurchase) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $purchaseId = $p->uuid('purchase_id');
        $f = Purchasing::fields($p);
        $status = $p->oneOf('status', ['ordered', 'in_transit', 'at_border']);
        $p->check();

        $row = DB::table('purchases')->where(['id' => $purchaseId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($row === null) {
            throw Rejection::notFound('Purchase', $purchaseId);
        }
        if ($row->status === 'received') {
            throw Rejection::stateConflict("Purchase {$row->reference} is already received and can no longer be changed.");
        }
        // Status only moves forward: ordered -> in_transit -> at_border (staying put is allowed).
        $order = ['ordered' => 0, 'in_transit' => 1, 'at_border' => 2];
        if (($order[$status] ?? 0) < ($order[$row->status] ?? 0)) {
            throw Rejection::invalidPayload(['status' => "cannot go back from {$row->status} to {$status}"]);
        }
        $fx = $row->fx_reference === null ? Purchasing::latestFx() : (float) $row->fx_reference;
        if ($fx === null && ($f['currency'] === 'USD' || array_filter($f['costs'], fn (array $c): bool => $c['currency'] === 'USD') !== [])) {
            throw Rejection::staleConfig('No USD to UGX reference is set; ask the group admin to set the exchange reference.');
        }

        return [Event::from($ctx, 'purchases.changed', [
            'purchase_id' => $purchaseId,
            ...$f,
            'fx_reference' => $fx,
            'status' => $status,
            'reference' => (string) $row->reference,
        ])];
    }
}
