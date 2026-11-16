<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Purchases\Commands\RecordPurchase;
use Tanaad\Purchases\Support\Purchasing;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Orders goods. The PO reference is allocated here from the site's purchase_order series and
 * the FX reference is captured from Config at this moment; both travel in the event so replay
 * reproduces them. A client-sent reference or fx is ignored.
 */
final class RecordPurchaseHandler implements Handler
{
    public function __construct(private readonly NumberSeries $series) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordPurchase) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $purchaseId = $p->optUuid('purchase_id') ?? (string) Str::uuid7();
        $f = Purchasing::fields($p);
        $orderedOn = Purchasing::businessDay($siteId, $ctx->occurredAt);
        if ($orderedOn > Purchasing::businessDay($siteId, \Carbon\CarbonImmutable::now())) {
            $p->error('ordered_on', 'must not be in the future');
        }
        $p->check();

        if (DB::table('purchases')->where('id', $purchaseId)->exists()) {
            throw Rejection::duplicateFact("Purchase {$purchaseId} is already recorded.");
        }
        $fx = Purchasing::latestFx();
        if ($fx === null && ($f['currency'] === 'USD' || array_filter($f['costs'], fn (array $c): bool => $c['currency'] === 'USD') !== [])) {
            throw Rejection::staleConfig('No USD to UGX reference is set; ask the group admin to set the exchange reference.');
        }

        return [Event::from($ctx, 'purchases.recorded', [
            'purchase_id' => $purchaseId,
            ...$f,
            'fx_reference' => $fx,
            'status' => 'ordered',
            'ordered_on' => $orderedOn,
            'reference' => $this->series->allocate($siteId, Purchasing::SERIES),
        ])];
    }
}
