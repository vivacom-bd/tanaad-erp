<?php

declare(strict_types=1);

namespace Tanaad\Documents\Builders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Tanaad\Documents\Support\Company;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Rejection;

/** A delivery note for a dispatch whose gate has passed. Site staff who handle dispatch, and group roles. */
final class DeliveryNoteBuilder implements DocumentBuilder
{
    private const array ROLES = [Role::Storekeeper->value, Role::Gate->value, Role::SiteManager->value, Role::Sales->value];

    public function __construct(private readonly ConnectionInterface $db, private readonly Access $access, private readonly Company $company) {}

    public function available(): bool
    {
        return Schema::hasTable('dispatches') && Schema::hasTable('delivery_notes');
    }

    public function build(User $user, string $id): array
    {
        $dispatch = $this->db->table('dispatches')->where('id', $id)->first();
        if ($dispatch === null) {
            throw Rejection::notFound('Dispatch', $id);
        }
        if (! $this->access->canAct($user, $dispatch->site_id, self::ROLES)) {
            throw Rejection::forbidden('You may not print delivery notes for this site.');
        }
        $note = $this->db->table('delivery_notes')->where('dispatch_id', $id)->first();
        if ($note === null) {
            throw Rejection::stateConflict('No delivery note has been issued for this dispatch yet; the gate has not passed.');
        }
        /** @var list<array{product_id?: string, bundles?: int|float, std_bundle_weight_kg?: int|float|string}> $lines */
        $lines = json_decode((string) $dispatch->lines, true, 512, JSON_THROW_ON_ERROR);
        $products = $this->db->table('products')->whereIn('id', array_column($lines, 'product_id'))->get(['id', 'code', 'internal_name'])->keyBy('id');
        $bundles = $this->db->table('dispatch_bundles')->where('dispatch_id', $id)->orderBy('id')->pluck('code')->all();

        return [
            ...$this->company->forSite($dispatch->site_id),
            'number' => $note->number,
            'issued_at' => $note->at,
            'released_via' => $note->via,
            'order_ref' => $dispatch->order_ref,
            'client_name' => $dispatch->client_name,
            'driver' => $dispatch->driver,
            'vehicle' => $dispatch->vehicle,
            'lines' => array_map(fn (array $l): array => [
                'product_id' => $l['product_id'] ?? null,
                'product_code' => $products[$l['product_id'] ?? '']->code ?? null,
                'product_name' => $products[$l['product_id'] ?? '']->internal_name ?? null,
                'bundles' => $l['bundles'] ?? 0,
                'std_bundle_weight_kg' => $l['std_bundle_weight_kg'] ?? null,
            ], $lines),
            'bundle_codes' => $bundles,
            'totals' => [
                'ordered_bundles' => (int) $dispatch->ordered_bundles, 'scanned_bundles' => count($bundles),
                'expected_kg' => $dispatch->expected_kg, 'gate_kg' => $dispatch->gate_kg, 'variance_pct' => $dispatch->variance_pct,
            ],
            'receipt' => [
                'received_by' => $dispatch->received_by, 'bundles_received' => $dispatch->bundles_received, 'discrepancy' => $dispatch->discrepancy,
                'delivered_at' => $dispatch->delivered_at, 'signature_hash' => $dispatch->signature_hash, 'photo_hash' => $dispatch->photo_hash,
            ],
        ];
    }
}
