<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Queries;

use Carbon\CarbonImmutable;
use Tanaad\Dispatch\Models\Dispatch;
use Tanaad\Dispatch\Models\DispatchBundle;
use Tanaad\Shared\Ledger\Rejection;

/** Dispatch read shapes in the prototype's camelCase (packages/domain Dispatch, ProofOfDelivery). */
final class DispatchQuery
{
    /** @return list<array<string, mixed>> */
    public function list(string $siteId, ?string $status): array
    {
        $rows = Dispatch::query()->where('site_id', $siteId)->when($status !== null, fn ($q) => $q->where('gate_status', $status))
            ->orderByDesc('at')->limit(500)->get();
        $bundles = DispatchBundle::query()->whereIn('dispatch_id', $rows->pluck('id')->all())->orderBy('seq')->orderBy('id')->get()->groupBy('dispatch_id');

        return array_values($rows->map(fn (Dispatch $d): array => self::shape($d, array_values($bundles->get($d->id, collect())->all())))->all());
    }

    /** @return array<string, mixed> */
    public function find(string $siteId, string $id): array
    {
        $d = Dispatch::query()->where(['site_id' => $siteId, 'id' => $id])->first() ?? throw Rejection::notFound('Dispatch', $id);

        return self::shape($d, array_values(DispatchBundle::query()->where('dispatch_id', $id)->orderBy('seq')->orderBy('id')->get()->all()));
    }

    /**
     * @param  list<DispatchBundle>  $bundles
     * @return array<string, mixed>
     */
    public static function shape(Dispatch $d, array $bundles): array
    {
        $iso = fn (mixed $v): ?string => $v === null ? null : CarbonImmutable::parse((string) $v)->toIso8601String();
        $lines = json_decode((string) $d->lines, true, 8, JSON_THROW_ON_ERROR);

        return [
            'id' => $d->id, 'siteId' => $d->site_id, 'orderRef' => $d->order_ref, 'clientName' => $d->client_name,
            'lines' => array_map(fn (array $l): array => ['productId' => $l['product_id'], 'bundles' => $l['bundles'], 'stdBundleWeightKg' => (float) $l['std_bundle_weight_kg']], $lines),
            'orderedBundles' => (int) $d->ordered_bundles, 'deliveryNoteNo' => $d->delivery_note_no,
            'scannedLabelIds' => array_map(fn (DispatchBundle $b): string => $b->label_id, $bundles),
            'scannedCodes' => array_map(fn (DispatchBundle $b): string => $b->code, $bundles),
            'expectedKg' => (float) $d->expected_kg, 'gateKg' => $d->gate_kg === null ? null : (float) $d->gate_kg,
            'variancePct' => $d->variance_pct === null ? null : (float) $d->variance_pct,
            'gateStatus' => $d->gate_status, 'gateReasons' => $d->gate_reasons === null ? [] : json_decode((string) $d->gate_reasons, true),
            'weighedBy' => $d->weighed_by, 'overrideBy' => $d->override_by, 'overrideReason' => $d->override_reason,
            'driver' => $d->driver, 'vehicle' => $d->vehicle, 'createdAt' => $iso($d->at), 'gatePassedAt' => $iso($d->gate_passed_at),
            'pod' => $d->delivered_at === null ? null : [
                'receiverName' => $d->received_by, 'bundlesCounted' => (int) $d->bundles_received,
                'bundlesExpected' => (int) $d->bundles_received - (int) $d->discrepancy, 'deliveredAt' => $iso($d->delivered_at),
                'hasSignature' => $d->signature_hash !== null, 'signatureHash' => $d->signature_hash, 'photoHash' => $d->photo_hash,
            ],
        ];
    }
}
