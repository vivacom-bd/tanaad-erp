<?php

declare(strict_types=1);

namespace Tanaad\Recycling\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Recycling\Commands\RecordCollectorIntake;
use Tanaad\Recycling\Support\Intakes;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/**
 * One collector delivery at the scale. Net kg and amount are computed here with
 * Arithmetic::intakeNet (any client net/amount is ignored). The voucher number is allocated
 * from the site's intake_voucher series at sync and travels in the event, so replay
 * reproduces it; an offline client's provisional number is kept alongside.
 * A deduction preset is a deduction_option ref item (user-managed), never a constant.
 */
final class RecordCollectorIntakeHandler implements Handler
{
    /** Upper bounds that keep kg and amount inside their DECIMAL columns. */
    public const float MAX_KG = 1e7;

    public const float MAX_PRICE = 1e6;

    public function __construct(private readonly NumberSeries $series) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordCollectorIntake) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $today = Intakes::businessDay($siteId, $ctx->occurredAt);
        $day = $p->optDate('day') ?? $today;
        if ($day > $today) {
            $p->error('day', "must not be after {$today}");
        }
        $deliveryId = $p->optUuid('delivery_id') ?? (string) Str::uuid7();
        $collector = $p->str('collector', 160);
        $gross = $p->num('gross_kg', positive: true);
        $tare = $p->num('tare_kg');
        $price = $p->num('price_per_kg', positive: true);
        $presetId = $p->optUuid('deduction_preset_id');
        $pct = $p->optNum('deduction_pct');
        if ($pct !== null && $pct > 100) {
            $p->error('deduction_pct', 'must be at most 100');
        }
        foreach (['gross_kg' => $gross, 'tare_kg' => $tare] as $key => $kg) {
            if ($kg > self::MAX_KG) {
                $p->error($key, 'must be at most 10000000');
            }
        }
        if ($price > self::MAX_PRICE) {
            $p->error('price_per_kg', 'must be at most 1000000');
        }
        if ($gross > 0 && $tare >= $gross) {
            $p->error('tare_kg', 'must be less than gross_kg');
        }
        if ($presetId !== null) {
            $preset = DB::table('ref_items')->where(['id' => $presetId, 'list' => 'deduction_option', 'active' => true])
                ->where(fn ($q) => $q->where('site_id', $siteId)->orWhereNull('site_id'))->whereNull('deleted_at')->first(['value']);
            if ($preset === null || $preset->value === null) {
                $p->error('deduction_preset_id', 'is not an active deduction option of this site');
            } elseif ($pct === null) {
                $pct = (float) $preset->value;
            } elseif (! Rounding::to($pct, 3)->isEqualTo(Rounding::to((string) $preset->value, 3))) {
                $p->error('deduction_pct', 'does not match the chosen deduction option');
            }
        }
        if ($pct === null && $presetId === null) {
            $p->error('deduction_pct', 'required');
        }
        $hasPhoto = $p->bool('has_scale_photo', false);
        $photoHash = $p->optStr('scale_photo_hash', 128);
        $provisional = $p->optStr('provisional_no', 64);
        $p->check();

        if (DB::table('collector_deliveries')->where('id', $deliveryId)->exists()) {
            throw Rejection::duplicateFact("Delivery {$deliveryId} is already recorded.");
        }
        $r = Arithmetic::intakeNet(['grossKg' => $gross, 'tareKg' => $tare, 'deductionPct' => $pct ?? 0.0, 'pricePerKg' => $price]);

        return [Event::from($ctx, 'recycling.collector_intake_recorded', [
            'delivery_id' => $deliveryId,
            'day' => $day,
            'collector' => $collector,
            'gross_kg' => $gross,
            'tare_kg' => $tare,
            'deduction_pct' => $pct ?? 0.0,
            'deduction_preset_id' => $presetId,
            'price_per_kg' => $price,
            'net_kg' => $r['netKg'],
            'amount' => $r['amount'],
            'currency' => 'UGX',
            'has_scale_photo' => $hasPhoto || $photoHash !== null,
            'scale_photo_hash' => $photoHash,
            'voucher_no' => $this->series->allocate($siteId, Intakes::VOUCHER_SERIES),
            'provisional_no' => $provisional,
        ])];
    }
}
