<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Dispatch\Commands\ConfirmDelivery;
use Tanaad\Dispatch\Commands\OverrideGate;
use Tanaad\Dispatch\Commands\RecordGateWeight;
use Tanaad\Dispatch\Commands\ScanBundle;
use Tanaad\Dispatch\Commands\StartDispatch;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Seven dispatches at the mats site, each built from a real seeded order (OrdersSeeder runs first; the order snapshot
 * is the one GET orders/{id}/dispatch-snapshot gives: order number, client name, site and lines of product id and
 * bundles) and loaded with real in-stock bundle labels of its products by the storekeeper. Orders' own reactions
 * move the order: dispatch.started -> picking, gate_passed / gate_overridden -> dispatched, delivered -> delivered.
 * Today: 1. passes the gate and gets its delivery note (still on the road), 2. is held at the gate (weighed 8% over:
 * an open gate_variance exception), 3. was held (weighed 7% under) and released by the site manager with a reason,
 * 4. passed and was delivered, with the receiver's signature hash. Three earlier orders passed and were delivered on
 * the days before (billed afterwards by OrdersBillingSeeder). A dispatch that already exists is left alone.
 * Run after StockSeeder and OrdersSeeder.
 */
final class DispatchSeeder extends Seeder
{
    public const string NAMESPACE = 'b4e7af65-1038-5dc9-a5c4-93f2a6db1b47';

    /** The Orders module's id namespace: an order id is the uuid5 of its seed key (read here by id, never by importing Orders). */
    private const string ORDERS_NAMESPACE = 'a3c51e7d-94b2-5f06-8d3a-61e0f2b7c948';

    private const string TODAY = '2026-11-10';

    /**
     * [order key, days ago, gate kg factor, what happens next, time of the first scan, driver, vehicle]
     *
     * @var list<array{string, int, float, string, string, string, string}>
     */
    private const array PLAN = [
        ['o_ss1_old', 4, 1.0, 'delivered', '08:00', 'Moses Kato', 'UBA 214K'],
        ['o_rw1_paid', 4, 1.0, 'delivered', '10:00', 'Ibrahim Okello', 'UBF 880T'],
        ['o_rw1_part', 3, 1.0, 'delivered', '08:30', 'Peter Ssemwogerere', 'UAZ 507C'],
        ['o_rw2_road', 0, 1.004, 'pass', '08:20', 'Joseph Lule', 'UBC 123M'],
        ['o_rw1_hold', 0, 1.08, 'hold', '09:05', 'Moses Kato', 'UBA 214K'],
        ['o_ss1_override', 0, 0.93, 'override', '09:40', 'Ibrahim Okello', 'UBF 880T'],
        ['o_rw2_deliv', 0, 1.0, 'delivered', '07:50', 'Peter Ssemwogerere', 'UAZ 507C'],
    ];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            $site = (string) DB::table('sites')->where('code', 'mats')->value('id');
            $store = (string) DB::table('users')->where('code', 'u_store_mats')->value('id');
            $manager = (string) DB::table('users')->where('code', 'u_sm_mats')->value('id');
            // In-stock bundle labels per product, taken from the newest code backwards (Stock placed the oldest ones).
            $pools = DB::table('labels')->where(['site_id' => $site, 'status' => 'in_stock', 'kind' => 'bundle'])->whereNull('zone_id')->whereNull('deleted_at')
                ->orderByDesc('code')->get(['code', 'product_id'])->groupBy('product_id')
                ->map(fn ($rows): array => array_map(fn ($r): string => (string) $r->code, $rows->all()))->all();
            $std = DB::table('products')->pluck('std_bundle_weight_kg', 'id');
            $taken = [];
            foreach (self::PLAN as [$key, $ago, $factor, $next, $time, $driver, $vehicle]) {
                $dispatchId = self::id("dispatch:{$key}");
                if (DB::table('dispatches')->where('id', $dispatchId)->exists()) {
                    continue;
                }
                $order = DB::table('orders')->where(['id' => Uuid::uuid5(self::ORDERS_NAMESPACE, $key)->toString(), 'site_id' => $site])->first(['number', 'client_name', 'lines', 'status']);
                if ($order === null || ! in_array((string) $order->status, ['approved', 'picking'], true)) {
                    continue;
                }
                $lines = array_map(fn (array $l): array => ['product_id' => (string) $l['product_id'], 'bundles' => (int) $l['bundles']], (array) json_decode((string) $order->lines, true));
                $codes = [];
                $expected = 0.0;
                foreach ($lines as $l) {
                    $take = array_slice($pools[$l['product_id']] ?? [], $taken[$l['product_id']] ?? 0, $l['bundles']);
                    $taken[$l['product_id']] = ($taken[$l['product_id']] ?? 0) + count($take);
                    $codes = [...$codes, ...$take];
                    $expected += (float) $std[$l['product_id']] * $l['bundles'];
                }
                if (count($codes) < array_sum(array_column($lines, 'bundles'))) {
                    continue;
                }
                $ref = (string) $order->number;
                $day = CarbonImmutable::parse(self::TODAY)->subDays($ago)->toDateString();
                $ctx = fn (string $name, string $at, string $by): CommandContext => new CommandContext($site, $by, null, CarbonImmutable::parse("{$day}T{$at}:00+03:00"), self::id("event:{$key}:{$name}"));
                $t = CarbonImmutable::parse($day.' '.$time);
                $bus->dispatch(new StartDispatch($ctx('start', $t->format('H:i'), $store), [
                    'dispatch_id' => $dispatchId, 'driver' => $driver, 'vehicle' => $vehicle,
                    'order' => ['order_ref' => $ref, 'client_name' => (string) $order->client_name, 'site' => 'mats', 'lines' => $lines],
                ]));
                foreach ($codes as $n => $code) {
                    $bus->dispatch(new ScanBundle($ctx("scan:{$code}", $t->addMinutes(2 + $n)->format('H:i'), $store), ['dispatch_id' => $dispatchId, 'code' => $code]));
                }
                $weighAt = $t->addMinutes(12 + count($codes))->format('H:i');
                $bus->dispatch(new RecordGateWeight($ctx('weigh', $weighAt, $store), ['dispatch_id' => $dispatchId, 'kg' => round($expected * $factor, 3)]));
                if ($next === 'override') {
                    $bus->dispatch(new OverrideGate($ctx('override', $t->addMinutes(30)->format('H:i'), $manager), [
                        'dispatch_id' => $dispatchId, 'reason' => 'Short load checked by hand; the client agreed to take it as is',
                    ]));
                }
                if ($next === 'delivered') {
                    $bus->dispatch(new ConfirmDelivery($ctx('deliver', $day === self::TODAY ? '11:30' : '14:30', $store), [
                        'dispatch_id' => $dispatchId, 'received_by' => 'Store clerk at the client', 'bundles_received' => count($codes),
                        'signature_hash' => hash('sha256', "signature:{$ref}"),
                    ]));
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
