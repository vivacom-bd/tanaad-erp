<?php

declare(strict_types=1);

namespace Tanaad\Dispatch;

use Illuminate\Support\ServiceProvider;
use Tanaad\Dispatch\Authorization\DispatchAuthorizer;
use Tanaad\Dispatch\Projectors\DispatchProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

/**
 * Dispatch (P3-05, P3-06): pick list, bundle scanning, gate weighbridge decision, override, proof of delivery.
 *
 * ORDER SNAPSHOT CONTRACT (stable; Orders arrives in Phase 4 and will emit this same shape):
 *   dispatch.started payload {
 *     dispatch_id?: uuid, driver: string<=120, vehicle: string<=64,
 *     order: { order_ref: string<=64, client_name: string<=255, site: <this site's code or id>,
 *              lines: [{ product_id: uuid (active product of this site), bundles: int 1..100000 }] (one line per product) }
 *   }
 *   The server adds std_bundle_weight_kg per line, ordered_bundles and expected_kg (Arithmetic::expectedCargoKg).
 *   One open (pending|hold) dispatch per order_ref and site.
 *
 * Client commands (REST and the offline /events envelope, same payloads):
 *   dispatch.started, dispatch.bundle_scanned {dispatch_id, code}, dispatch.bundle_unscanned {dispatch_id, code},
 *   dispatch.gate_weighed {dispatch_id, kg}, dispatch.gate_overridden {dispatch_id, reason},
 *   dispatch.delivered {dispatch_id, received_by, bundles_received, signature_hash, photo_hash?}.
 * Server-only follow-ups (no factory, cannot be sent by a client): dispatch.gate_passed, dispatch.gate_held,
 *   dispatch.label_dispatched (Labels flips the label to dispatched). The gate decision and every number in
 *   them is computed server-side; a client's decision/variance is ignored.
 *
 * Delivery note numbers come from the site's number_series "delivery_note" (Config DefineNumberSeries), whose
 * `next` continues the paper book (bags 4817 → DN-4817, pad 1; others DN-00001, pad 5). Allocated only at gate
 * pass or override, under the series row lock, and carried in the event payload (Replay-stable).
 *
 * Override: Site Manager only, mandatory reason, never the weigher or a loader of the lorry. The prototype's
 * PIN re-check lives in Identity, which a module may not import: the signed-in Site Manager session is the
 * re-authentication.
 */
final class DispatchServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'dispatch.started' => [Commands\StartDispatch::class, Handlers\StartDispatchHandler::class, Factories\StartDispatchFactory::class],
            'dispatch.bundle_scanned' => [Commands\ScanBundle::class, Handlers\ScanBundleHandler::class, Factories\ScanBundleFactory::class],
            'dispatch.bundle_unscanned' => [Commands\UnscanBundle::class, Handlers\UnscanBundleHandler::class, Factories\UnscanBundleFactory::class],
            'dispatch.gate_weighed' => [Commands\RecordGateWeight::class, Handlers\RecordGateWeightHandler::class, Factories\RecordGateWeightFactory::class],
            'dispatch.gate_overridden' => [Commands\OverrideGate::class, Handlers\OverrideGateHandler::class, Factories\OverrideGateFactory::class],
            'dispatch.delivered' => [Commands\ConfirmDelivery::class, Handlers\ConfirmDeliveryHandler::class, Factories\ConfirmDeliveryFactory::class],
        ];
        foreach ($map as $type => [$command, $handler, $factory]) {
            $registry->command($command, $handler, DispatchAuthorizer::class)->eventType($type, $factory);
        }
        $registry->projectors(DispatchProjector::class);

        foreach (['dispatches', 'dispatch_bundles', 'delivery_notes'] as $table) {
            $snapshots->register($table, $table, roles: DispatchAuthorizer::READ_ROLES);
        }
    }
}
