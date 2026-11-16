<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->store = $token('u_store_mats');
    $this->sup = $token('u_sup_mats');
    $this->sm = $token('u_sm_mats');
    $this->ceo = $token('u_ceo1');
    $this->cashier = $token('u_cash_mats');
    $this->bagsStore = $token('u_store_bags');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->bags = (string) Site::query()->where('code', 'bags')->value('id');
    DB::table('stages')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'code' => 'finishing', 'name' => 'Finishing',
        'sequence' => 1, 'input_unit' => 'pcs', 'output_unit' => 'pcs', 'produces_label' => 'bundle']);
    $product = function (string $site, string $code): string {
        $id = (string) Str::uuid7();
        DB::table('products')->insert([
            'id' => $id, 'site_id' => $site, 'code' => $code, 'internal_name' => $code, 'commercial_name' => $code, 'kind' => 'mat',
            'bundle_pieces' => 50, 'std_bundle_weight_kg' => 20, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed',
            'seq' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $zone = function (string $site, string $name): string {
        $id = (string) Str::uuid7();
        DB::table('zones')->insert(['id' => $id, 'site_id' => $site, 'name' => $name, 'seq' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $this->product = $product($this->mats, 'p_mat');
    $this->product2 = $product($this->mats, 'p_mat2');
    $this->zoneA = $zone($this->mats, 'Finished A');
    $this->zoneB = $zone($this->mats, 'Finished B');
    $this->bagsZone = $zone($this->bags, 'Inbound');
});

function stockBundle(Tests\TestCase $t, array $over = []): array
{
    return $t->withToken($t->sup)->postJson('/api/v1/sites/mats/labels', [
        'kind' => 'bundle', 'stageCode' => 'finishing', 'productId' => $t->product, 'qtyUnits' => 50, 'qtyKg' => 20.25, 'zoneId' => $t->zoneA, ...$over,
    ])->assertCreated()->json('label');
}

function stockPost(Tests\TestCase $t, string $token, string $path, array $body): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/stock'.$path, $body);
}

/** @return array<string, array{bundles: int, pieces: string, kg: string}> keyed product|zone, non-zero only */
function onHandTable(string $site): array
{
    $out = [];
    foreach (DB::table('stock_on_hand')->where('site_id', $site)->get() as $r) {
        if ((int) $r->bundles !== 0 || (float) $r->pieces != 0 || (float) $r->kg != 0) {
            $out[$r->product_id.'|'.($r->zone_id ?? 'none')] = ['bundles' => (int) $r->bundles, 'pieces' => number_format((float) $r->pieces, 3, '.', ''), 'kg' => number_format((float) $r->kg, 3, '.', '')];
        }
    }
    ksort($out);

    return $out;
}

/** The same figures from the labels table (Labels' own projection), read by table name. */
function inStockLabelsTable(string $site): array
{
    $out = [];
    $rows = DB::table('labels')->where(['site_id' => $site, 'status' => 'in_stock'])->whereNotNull('product_id')
        ->selectRaw('product_id, zone_id, count(*) as b, sum(qty_units) as u, sum(qty_kg) as k')->groupBy('product_id', 'zone_id')->get();
    foreach ($rows as $r) {
        $out[$r->product_id.'|'.($r->zone_id ?? 'none')] = ['bundles' => (int) $r->b, 'pieces' => number_format((float) $r->u, 3, '.', ''), 'kg' => number_format((float) $r->k, 3, '.', '')];
    }
    ksort($out);

    return $out;
}

function stockRows(): array
{
    $strip = fn ($rows) => $rows->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();
    $out = [];
    foreach (['stock_on_hand' => 'id', 'stock_label_positions' => 'label_id', 'stock_movements' => 'id', 'stock_transfers' => 'id', 'stock_counts' => 'id'] as $t => $k) {
        $out[$t] = $strip(DB::table($t)->orderBy($k)->get());
    }
    $out['locks'] = DB::table('day_locks')->where('ledger', 'like', 'stock:%')->orderBy('ledger')->get(['site_id', 'ledger', 'day', 'locked_by_event', 'reopened_by_event'])->map(fn ($r) => (array) $r)->all();

    return $out;
}

it('gate: on hand equals in-stock labels after create, move, scrap, consume, dispatch and Replay', function (): void {
    $a = stockBundle($this);
    $b = stockBundle($this, ['qtyKg' => 19.5]);
    $c = stockBundle($this, ['productId' => $this->product2, 'zoneId' => $this->zoneB]);
    $d = stockBundle($this);
    $e = stockBundle($this, ['zoneId' => null]);
    stockBundle($this, ['kind' => 'roll', 'status' => 'created']);
    expect(onHandTable($this->mats))->toBe(inStockLabelsTable($this->mats));

    stockPost($this, $this->store, '/moves', ['code' => $a['code'], 'toZoneId' => $this->zoneB])->assertCreated();
    expect(DB::table('labels')->where('id', $a['id'])->value('zone_id'))->toBe($this->zoneB);
    stockPost($this, $this->store, '/moves', ['labelId' => $a['id'], 'toZoneId' => $this->zoneB])->assertStatus(409);
    stockPost($this, $this->store, "/labels/{$b['code']}/scrap", ['reason' => 'Crushed by forklift'])->assertCreated();
    stockPost($this, $this->store, "/labels/{$b['code']}/scrap", ['reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    stockPost($this, $this->store, "/labels/{$c['code']}/scrap", [])->assertStatus(422);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$c['code']}/consume", ['consumedByStage' => 'dispatch'])->assertCreated();
    stockPost($this, $this->store, '/moves', ['code' => $c['code'], 'toZoneId' => $this->zoneA])->assertStatus(409);
    stockPost($this, $this->store, '/moves', ['code' => $e['code'], 'toZoneId' => $this->zoneA])->assertCreated();
    $stored = app(Tanaad\Shared\Ledger\EventStore::class)->append([new Tanaad\Shared\Ledger\Event(
        id: (string) Str::uuid7(), type: 'dispatch.label_dispatched', siteId: $this->mats, actorId: null, deviceId: null,
        occurredAt: CarbonImmutable::now(), payload: ['code' => $d['code'], 'dispatch_id' => (string) Str::uuid7()],
    )]);
    app(Tanaad\Shared\Ledger\CommandBus::class)->project($stored[0]);

    expect(DB::table('labels')->where('id', $b['id'])->value('status'))->toBe('scrapped')
        ->and(onHandTable($this->mats))->toBe(inStockLabelsTable($this->mats))
        ->and(onHandTable($this->mats))->toHaveCount(2);

    $stock = $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/stock')->assertOk()->json('stock');
    expect($stock)->toHaveCount(1)->and($stock[0]['bundles'])->toBe(2)->and($stock[0]['kg'])->toEqual(40.5);

    $kinds = array_column($this->withToken($this->store)->getJson('/api/v1/sites/mats/stock/movements')->assertOk()->json('data'), 'kind');
    expect($kinds)->toContain('production_in', 'label_move', 'scrap', 'consumed', 'dispatch_out');

    $before = stockRows();
    app(Replay::class)->rebuild();
    expect(stockRows())->toEqual($before)->and(onHandTable($this->mats))->toBe(inStockLabelsTable($this->mats));

});

it('receives, moves and adjusts by quantity, refusing to go below zero', function (): void {
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 10, 'qtyUnits' => 500, 'qtyKg' => 200.5, 'cause' => 'Supplier DN 44'])->assertCreated();
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA])->assertStatus(422);
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->bagsZone, 'bundles' => 1])->assertStatus(422);
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'qtyKg' => 1e12])->assertStatus(422);

    stockPost($this, $this->store, '/moves', ['productId' => $this->product, 'fromZoneId' => $this->zoneA, 'toZoneId' => $this->zoneB, 'bundles' => 4, 'qtyUnits' => 200, 'qtyKg' => 80])->assertCreated();
    stockPost($this, $this->store, '/moves', ['productId' => $this->product, 'fromZoneId' => $this->zoneA, 'toZoneId' => $this->zoneB, 'bundles' => 7])->assertStatus(409);
    stockPost($this, $this->store, '/moves', ['kind' => 'adjust', 'productId' => $this->product, 'zoneId' => $this->zoneB, 'bundles' => -1])->assertStatus(422);
    stockPost($this, $this->store, '/moves', ['kind' => 'adjust', 'productId' => $this->product, 'zoneId' => $this->zoneB, 'bundles' => -1, 'qtyUnits' => -50, 'qtyKg' => -20, 'reason' => 'Torn bundle found'])->assertCreated();
    stockPost($this, $this->store, '/moves', ['kind' => 'adjust', 'productId' => $this->product, 'zoneId' => $this->zoneB, 'bundles' => -9, 'reason' => 'Too much'])->assertStatus(409);

    expect(onHandTable($this->mats))->toBe([
        $this->product.'|'.$this->zoneA => ['bundles' => 6, 'pieces' => '300.000', 'kg' => '120.500'],
        $this->product.'|'.$this->zoneB => ['bundles' => 3, 'pieces' => '150.000', 'kg' => '60.000'],
    ]);
    $adj = $this->withToken($this->sm)->getJson('/api/v1/sites/mats/stock/movements?kind=adjust')->assertOk()->json('data');
    expect($adj)->toHaveCount(1)->and($adj[0]['reason'])->toBe('Torn bundle found')->and($adj[0]['bundles'])->toBe(-1);
});

it('transfers between sites: sender books out, only the destination receives, discrepancy recorded', function (): void {
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 10, 'qtyKg' => 200])->assertCreated();
    $id = (string) Str::uuid7();
    stockPost($this, $this->store, '/transfers', ['transferId' => $id, 'toSiteId' => 'bags', 'productId' => $this->product, 'fromZoneId' => $this->zoneA, 'bundles' => 5, 'qtyKg' => 100])->assertCreated();
    stockPost($this, $this->store, '/transfers', ['transferId' => $id, 'toSiteId' => 'bags', 'productId' => $this->product, 'fromZoneId' => $this->zoneA, 'bundles' => 1])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    stockPost($this, $this->store, '/transfers', ['toSiteId' => 'mats', 'productId' => $this->product, 'fromZoneId' => $this->zoneA, 'bundles' => 1])->assertStatus(422);
    stockPost($this, $this->store, '/transfers', ['toSiteId' => 'bags', 'productId' => $this->product, 'fromZoneId' => $this->zoneA, 'bundles' => 6])->assertStatus(409);

    // The sender may not receive its own transfer.
    stockPost($this, $this->store, "/transfers/{$id}/receive", ['toZoneId' => $this->zoneB, 'productId' => $this->product])->assertForbidden();
    $recv = fn (array $b) => $this->withToken($this->bagsStore)->postJson("/api/v1/sites/bags/stock/transfers/{$id}/receive", ['toZoneId' => $this->bagsZone, ...$b]);
    // No product with the same code at bags, and a mats product id is not the receiving site's.
    $recv([])->assertStatus(422)->assertJsonPath('details.errors.product_id', 'required');
    $recv(['productId' => $this->product])->assertStatus(422)->assertJsonPath('details.errors.product_id', 'not found at this site');
    $bagsProduct = (string) Str::uuid7();
    DB::table('products')->insert(['id' => $bagsProduct, 'site_id' => $this->bags, 'code' => 'p_bag_mat', 'internal_name' => 'Mat', 'commercial_name' => 'Mat', 'kind' => 'mat',
        'bundle_pieces' => 50, 'std_bundle_weight_kg' => 20, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed', 'seq' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $r = $recv(['productId' => $bagsProduct, 'bundles' => 4, 'qtyKg' => 80])->assertCreated();
    expect($r->json('events.0.payload.product_id'))->toBe($bagsProduct)->and($r->json('events.0.payload.sent_product_id'))->toBe($this->product);
    $this->withToken($this->bagsStore)->postJson("/api/v1/sites/bags/stock/transfers/{$id}/receive", ['toZoneId' => $this->bagsZone, 'productId' => $bagsProduct])->assertStatus(409);

    $t = $this->withToken($this->bagsStore)->getJson('/api/v1/sites/bags/stock/transfers')->assertOk()->json('transfers.0');
    expect($t['status'])->toBe('received')->and($t['discrepancyBundles'])->toBe(1)->and($t['discrepancyKg'])->toEqual(20);
    expect(onHandTable($this->mats)[$this->product.'|'.$this->zoneA]['bundles'])->toBe(5)
        ->and(onHandTable($this->bags))->toBe([$bagsProduct.'|'.$this->bagsZone => ['bundles' => 4, 'pieces' => '0.000', 'kg' => '80.000']])
        ->and(DB::table('stock_on_hand')->where('site_id', $this->bags)->where('product_id', $this->product)->exists())->toBeFalse();
    $before = stockRows();
    app(Replay::class)->rebuild();
    expect(stockRows())->toEqual($before);
});

it('rejects a transfer receipt without the receiving site product_id (422)', function (): void {
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 2])->assertCreated();
    $id = (string) Str::uuid7();
    stockPost($this, $this->store, '/transfers', ['transferId' => $id, 'toSiteId' => 'bags', 'productId' => $this->product, 'fromZoneId' => $this->zoneA, 'bundles' => 2])->assertCreated();
    $this->withToken($this->bagsStore)->postJson("/api/v1/sites/bags/stock/transfers/{$id}/receive", ['toZoneId' => $this->bagsZone])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
    expect(DB::table('stock_transfers')->where('id', $id)->value('status'))->toBe('sent');
});

it('closes a counted zone-product day to stock changes until reopened', function (): void {
    $a = stockBundle($this);
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 3])->assertCreated();
    stockPost($this, $this->store, '/counts', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'countedBundles' => 3, 'reason' => 'One short on the floor'])
        ->assertCreated()->assertJsonPath('events.1.type', 'stock.adjusted');
    $day = CarbonImmutable::now('Africa/Kampala')->toDateString();
    $locked = fn ($r) => $r->assertStatus(409)->assertJsonPath('code', 'day_locked');
    $locked(stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 1]));
    $locked(stockPost($this, $this->store, '/moves', ['productId' => $this->product, 'fromZoneId' => $this->zoneA, 'toZoneId' => $this->zoneB, 'bundles' => 1]));
    $locked(stockPost($this, $this->store, '/moves', ['productId' => $this->product, 'fromZoneId' => $this->zoneB, 'toZoneId' => $this->zoneA, 'bundles' => 1]));
    $locked(stockPost($this, $this->sm, '/moves', ['kind' => 'adjust', 'productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => -1, 'reason' => 'Damaged']));
    $locked(stockPost($this, $this->store, '/moves', ['code' => $a['code'], 'toZoneId' => $this->zoneB]));
    $locked(stockPost($this, $this->sm, "/labels/{$a['code']}/scrap", ['reason' => 'Torn bundle']));
    $locked(stockPost($this, $this->store, '/transfers', ['toSiteId' => 'bags', 'productId' => $this->product, 'fromZoneId' => $this->zoneA, 'bundles' => 1]));
    // Another product in the same zone is unaffected.
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product2, 'zoneId' => $this->zoneA, 'bundles' => 1])->assertCreated();

    stockPost($this, $this->sm, '/counts/reopen', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'day' => $day, 'reason' => 'Delivery arriving'])->assertCreated();
    stockPost($this, $this->store, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 1])->assertCreated();
});

it('counts a zone: variance adjusts, the zone-product day locks, a manager reopens', function (): void {
    stockBundle($this);
    stockBundle($this);
    stockBundle($this);
    $day = CarbonImmutable::now('Africa/Kampala')->toDateString();
    stockPost($this, $this->store, '/counts', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'countedBundles' => 2])->assertStatus(422);
    $r = stockPost($this, $this->store, '/counts', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'countedBundles' => 2, 'reason' => 'One bundle missing'])->assertCreated();
    expect(array_column($r->json('events'), 'type'))->toBe(['stock.counted', 'stock.adjusted'])
        ->and($r->json('events.0.payload.system_bundles'))->toBe(3)->and($r->json('events.1.payload.qty_kg'))->toBe('-20.000');
    expect(onHandTable($this->mats)[$this->product.'|'.$this->zoneA]['bundles'])->toBe(2);

    stockPost($this, $this->store, '/counts', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'countedBundles' => 2])->assertStatus(409)->assertJsonPath('code', 'day_locked');
    stockPost($this, $this->store, '/counts', ['zoneId' => $this->zoneA, 'productId' => $this->product2, 'countedBundles' => 0])->assertCreated();
    stockPost($this, $this->store, '/counts', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'countedBundles' => 2, 'day' => '2099-01-01'])->assertStatus(422);

    stockPost($this, $this->store, '/counts/reopen', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'day' => $day, 'reason' => 'Recount'])->assertForbidden();
    stockPost($this, $this->sm, '/counts/reopen', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'day' => $day])->assertStatus(422);
    stockPost($this, $this->sm, '/counts/reopen', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'day' => $day, 'reason' => 'Bundle found behind rack'])->assertCreated();
    stockPost($this, $this->sm, '/counts/reopen', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'day' => $day, 'reason' => 'again'])->assertStatus(409);
    stockPost($this, $this->store, '/counts', ['zoneId' => $this->zoneA, 'productId' => $this->product, 'countedBundles' => 3, 'reason' => 'Found it'])->assertCreated();

    $v = $this->withToken($this->ceo)->getJson("/api/v1/sites/mats/stock/count-variance?day={$day}")->assertOk();
    expect($v->json('counts'))->toHaveCount(3)->and($v->json('counts.0.status'))->toBe('reopened')
        ->and($v->json('totals.counted'))->toBe(2)->and($v->json('totals.netBundles'))->toBe(1);
    $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/stock/count-variance')->assertStatus(422);

    $before = stockRows();
    app(Replay::class)->rebuild();
    expect(stockRows())->toEqual($before);
    // Replay restores the lock.
    expect(app(Tanaad\Shared\Ledger\DayLocks::class)->isLocked($this->mats, Tanaad\Stock\Support\StockBook::countLedger($this->zoneA, $this->product), $day))->toBeTrue();
});

it('scopes reads, writes and the snapshot by site and role', function (): void {
    $a = stockBundle($this);
    stockPost($this, $this->cashier, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 1])->assertForbidden();
    stockPost($this, $this->ceo, '/receipts', ['productId' => $this->product, 'zoneId' => $this->zoneA, 'bundles' => 1])->assertForbidden();
    stockPost($this, $this->bagsStore, "/labels/{$a['code']}/scrap", ['reason' => 'nope nope'])->assertForbidden();
    $this->withToken($this->bagsStore)->getJson('/api/v1/sites/mats/stock')->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/stock/movements')->assertForbidden();
    $this->withToken($this->bagsStore)->getJson('/api/v1/sites/mats/stock/count-variance?day=2026-11-10')->assertForbidden();
    $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/stock')->assertOk();
    $this->withToken($this->store)->getJson('/api/v1/sites/mats/snapshots/stock_on_hand?since=0')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/snapshots/stock_on_hand?since=0')->assertForbidden();
    // A bags product or zone is not found at mats.
    stockPost($this, $this->store, '/moves', ['code' => $a['code'], 'toZoneId' => $this->bagsZone])->assertStatus(422);
});

it('accepts offline envelopes idempotently, including an adjustment', function (): void {
    stockBundle($this);
    $ev = ['id' => (string) Str::uuid7(), 'type' => 'stock.adjusted', 'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        'payload' => ['product_id' => $this->product, 'zone_id' => $this->zoneA, 'bundles' => 2, 'reason' => 'Found two bundles']];
    $send = fn () => $this->withToken($this->store)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$ev]]);
    expect($send()->assertOk()->json('results.0.status'))->toBe('accepted')
        ->and($send()->assertOk()->json('results.0.status'))->toBe('duplicate')
        ->and(onHandTable($this->mats)[$this->product.'|'.$this->zoneA]['bundles'])->toBe(3);
    $mv = ['id' => (string) Str::uuid7(), 'type' => 'stock.moved', 'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        'payload' => ['product_id' => $this->product, 'from_zone_id' => $this->zoneA, 'to_zone_id' => $this->zoneB, 'bundles' => 9]];
    expect($this->withToken($this->store)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$mv]])->json('results.0.code'))->toBe('state_conflict');
});
