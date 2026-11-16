<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Recycling\Commands\PayCollector;
use Tanaad\Recycling\Support\Intakes;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Pays a collector for one delivery, in cash, on the command's business day. The amount is
 * recomputed from the stored net kg × price (whole shillings, half-up). The Cash module books
 * recycling.collector_paid (by type string) as a materials cash-out, so that cash day must be open.
 */
final class PayCollectorHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof PayCollector) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $deliveryId = $p->uuid('delivery_id');
        $paymentId = $p->optUuid('payment_id') ?? (string) Str::uuid7();
        $p->check();

        $day = Intakes::businessDay($siteId, $ctx->occurredAt);
        if ($day > Intakes::businessDay($siteId, CarbonImmutable::now())) {
            throw Rejection::invalidPayload(['day' => 'must not be in the future']);
        }
        // Locked so two concurrent pays of one delivery cannot both pass the paid check.
        $d = DB::table('collector_deliveries')->where(['id' => $deliveryId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($d === null) {
            throw Rejection::notFound('Collector delivery', $deliveryId);
        }
        if ((bool) $d->paid) {
            throw Rejection::stateConflict("Delivery {$d->voucher_no} is already paid.");
        }
        // The id becomes the cash entry id too: a reused one is a duplicate, not a 500.
        if (DB::table('cash_entries')->where('id', $paymentId)->exists()) {
            throw Rejection::duplicateFact("Payment {$paymentId} is already recorded.");
        }
        $this->locks->assertOpen($siteId, Intakes::CASH_LEDGER, $day);

        return [Event::from($ctx, 'recycling.collector_paid', [
            'payment_id' => $paymentId,
            'delivery_id' => $deliveryId,
            'collector' => (string) $d->collector,
            'day' => $day,
            'delivery_day' => substr((string) $d->day, 0, 10),
            'voucher_no' => (string) $d->voucher_no,
            'net_kg' => (float) $d->net_kg,
            'price_per_kg' => (float) $d->price_per_kg,
            'amount' => Intakes::payAmount((string) $d->net_kg, (string) $d->price_per_kg),
            'currency' => 'UGX',
            'has_scale_photo' => (bool) $d->has_scale_photo,
        ])];
    }
}
