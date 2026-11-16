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
    $this->sm = $token('u_sm_mats');
    $this->ceo = $token('u_ceo1');
    $this->sup = $token('u_sup_mats');
    $this->cashier = $token('u_cash_mats');
    $this->otherStore = $token('u_store_bags');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->bags = (string) Site::query()->where('code', 'bags')->value('id');
    $now = now();
    DB::table('site_settings')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'gate_tolerance_pct' => 2, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('number_series')->insert(['site_id' => $this->mats, 'kind' => 'delivery_note', 'prefix' => 'DN', 'pad' => 1, 'next' => 4817]);
    DB::table('stages')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'code' => 'finishing', 'name' => 'Finishing',
        'sequence' => 1, 'input_unit' => 'pcs', 'output_unit' => 'pcs', 'produces_label' => 'bundle']);
    $product = fn (string $site, string $code, float $kg): string => tap((string) Str::uuid7(), fn (string $id) => DB::table('products')->insert([
        'id' => $id, 'site_id' => $site, 'code' => $code, 'internal_name' => $code, 'kind' => 'mat', 'bundle_pieces' => 50,
        'std_bundle_weight_kg' => $kg, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed', 'active' => true, 'seq' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]));
    $this->mat = $product($this->mats, 'p_mat', 38);
    $this->other = $product($this->mats, 'p_other', 25.2);
    $this->bagsProduct = $product($this->bags, 'p_bag', 10);
});

function dsBundles(Tests\TestCase $t, int $n, ?string $product = null): array
{
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $codes[] = $t->withToken($t->store)->postJson('/api/v1/sites/mats/labels', [
            'kind' => 'bundle', 'stageCode' => 'finishing', 'productId' => $product ?? $t->mat, 'qtyUnits' => 50, 'qtyKg' => 38,
        ])->assertCreated()->json('label.code');
    }

    return $codes;
}

function dsStart(Tests\TestCase $t, array $lines, string $ref = 'SO-2301', ?string $token = null): Illuminate\Testing\TestResponse
{
    return $t->withToken($token ?? $t->store)->postJson('/api/v1/sites/mats/dispatches', [
        'driver' => 'Okello', 'vehicle' => 'UBA 123X',
        'order' => ['orderRef' => $ref, 'clientName' => 'Kigali Traders', 'site' => 'mats', 'lines' => $lines],
    ]);
}

function dsScan(Tests\TestCase $t, string $id, string $code, ?string $token = null): Illuminate\Testing\TestResponse
{
    return $t->withToken($token ?? $t->store)->postJson("/api/v1/sites/mats/dispatches/{$id}/scan", ['code' => $code]);
}

function dsRows(): array
{
    $strip = fn (string $table, string $order) => DB::table($table)->orderBy($order)->get()->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at'], $a['id']);

        return $a;
    })->all();

    return [$strip('dispatches', 'at'), $strip('dispatch_bundles', 'seq'), $strip('delivery_notes', 'seq'), $strip('exceptions', 'seq')];
}

function dsEnvelope(Tests\TestCase $t, string $token, string $type, array $payload, ?string $id = null): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [[
        'id' => $id ?? (string) Str::uuid7(), 'type' => $type, 'occurred_at' => CarbonImmutable::now()->toIso8601String(), 'payload' => $payload,
    ]]]);
}

it('holds a lorry ten bundles short, the site manager overrides with a reason, and the note number is stable', function (): void {
    $codes = dsBundles($this, 20);
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 30]])->assertCreated()->json('dispatch');
    expect($d['expectedKg'])->toEqual(1140)->and($d['gateStatus'])->toBe('pending')->and($d['deliveryNoteNo'])->toBeNull();
    foreach ($codes as $c) {
        dsScan($this, $d['id'], $c)->assertCreated();
    }

    // Weighed through the offline envelope; any client-sent decision is ignored.
    $weighId = (string) Str::uuid7();
    $r = dsEnvelope($this, $this->store, 'dispatch.gate_weighed', ['dispatch_id' => $d['id'], 'kg' => 760, 'decision' => 'pass', 'variance_pct' => 0], $weighId)->assertOk();
    expect($r->json('results.0.status'))->toBe('accepted');
    $held = $this->withToken($this->sup)->getJson("/api/v1/sites/mats/dispatches/{$d['id']}")->assertOk()->json('dispatch');
    expect($held['gateStatus'])->toBe('hold')->and($held['variancePct'])->toEqual(-33.33)->and($held['deliveryNoteNo'])->toBeNull()
        ->and($held['gateReasons'])->toBe(['Weight variance -33.33% exceeds ±2%', 'Scanned 20 bundles, order has 30']);
    expect(DB::table('labels')->where('status', 'dispatched')->count())->toBe(0);

    $ex = collect($this->withToken($this->ceo)->getJson('/api/v1/exceptions')->assertOk()->json('data'))->firstWhere('kind', 'gate_variance');
    expect($ex)->not->toBeNull()->and($ex['severity'])->toBe('critical')->and($ex['status'])->toBe('open');
    expect(json_decode((string) DB::table('exceptions')->where('kind', 'gate_variance')->value('facts'), true)['alert']['audience'])->toContain('ceo');

    // The weigher/loader cannot release it; a reason is mandatory.
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/override", ['reason' => 'Client agreed'])->assertForbidden();
    $this->withToken($this->sm)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/override", [])->assertStatus(422);
    $o = $this->withToken($this->sm)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/override", ['reason' => 'Client takes 20 now, 10 next week'])
        ->assertCreated()->json('dispatch');
    expect($o['gateStatus'])->toBe('overridden')->and($o['deliveryNoteNo'])->toBe('DN-4817')->and($o['overrideReason'])->toBe('Client takes 20 now, 10 next week');
    expect(DB::table('labels')->where('status', 'dispatched')->count())->toBe(20);
    $this->withToken($this->sm)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/override", ['reason' => 'again please'])->assertStatus(409);

    $ex = DB::table('exceptions')->where('kind', 'gate_variance')->first();
    expect($ex->status)->toBe('approved')->and($ex->decided_by)->toBe(User::query()->where('code', 'u_sm_mats')->value('id'));
    // The audit is the ledger: both the hold and the override are there, the override with its reason.
    $types = DB::table('events')->where('site_id', $this->mats)->where('type', 'like', 'dispatch.gate%')->orderBy('seq')->pluck('type')->all();
    expect($types)->toBe(['dispatch.gate_weighed', 'dispatch.gate_held', 'dispatch.gate_overridden'])
        ->and(DB::table('events')->where('type', 'dispatch.gate_overridden')->value('reason'))->toBe('Client takes 20 now, 10 next week');

    // Proof of delivery with a short count raises pod_discrepancy.
    $sig = hash('sha256', 'sig');
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/delivery", ['receivedBy' => 'J. Uwase', 'bundlesReceived' => 19, 'signatureHash' => 'nothex'])->assertStatus(422);
    $pod = $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/delivery", ['receivedBy' => 'J. Uwase', 'bundlesReceived' => 19, 'signatureHash' => $sig])
        ->assertCreated()->json('dispatch.pod');
    expect($pod)->toMatchArray(['receiverName' => 'J. Uwase', 'bundlesCounted' => 19, 'bundlesExpected' => 20, 'hasSignature' => true]);
    expect(DB::table('exceptions')->where('kind', 'pod_discrepancy')->value('severity'))->toBe('critical');
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/delivery", ['receivedBy' => 'X', 'bundlesReceived' => 20, 'signatureHash' => $sig])->assertStatus(409);
    // Duplicate re-ingest of the weighing is a no-op; Replay rebuilds identically and keeps DN-4817.
    expect(dsEnvelope($this, $this->store, 'dispatch.gate_weighed', ['dispatch_id' => $d['id'], 'kg' => 760], $weighId)->json('results.0.status'))->toBe('duplicate');
    $before = dsRows();
    app(Replay::class)->rebuild();
    expect(dsRows())->toEqual($before)
        ->and(DB::table('delivery_notes')->pluck('number')->all())->toBe(['DN-4817'])
        ->and((int) DB::table('number_series')->where(['site_id' => $this->mats, 'kind' => 'delivery_note'])->value('next'))->toBe(4818)
        ->and(DB::table('exceptions')->where('kind', 'gate_variance')->value('status'))->toBe('approved')
        ->and(DB::table('exceptions')->where('kind', 'pod_discrepancy')->count())->toBe(1);

});

it('passes a full, in-tolerance load and allocates the note at the gate, not at start', function (): void {
    $codes = dsBundles($this, 3);
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 3]])->json('dispatch');
    expect((int) DB::table('number_series')->where(['site_id' => $this->mats, 'kind' => 'delivery_note'])->value('next'))->toBe(4817);
    foreach ($codes as $c) {
        dsScan($this, $d['id'], $c);
    }
    $r = $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 112])->assertCreated();
    expect($r->json('dispatch.gateStatus'))->toBe('pass')->and($r->json('dispatch.variancePct'))->toEqual(-1.75)->and($r->json('dispatch.deliveryNoteNo'))->toBe('DN-4817')
        ->and($r->json('events'))->toBe(['dispatch.gate_weighed', 'dispatch.gate_passed', 'dispatch.label_dispatched', 'dispatch.label_dispatched', 'dispatch.label_dispatched']);
    expect(DB::table('labels')->whereIn('code', $codes)->pluck('status')->unique()->all())->toBe(['dispatched'])
        ->and(DB::table('exceptions')->count())->toBe(0);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 112])->assertStatus(409);
    $this->withToken($this->sm)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/override", ['reason' => 'not held at all'])->assertStatus(409);
});

it('resolves the gate_variance when a corrected load re-weighs within tolerance', function (): void {
    $codes = dsBundles($this, 2);
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 2]])->json('dispatch');
    dsScan($this, $d['id'], $codes[0]);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 38])->assertCreated()->assertJsonPath('dispatch.gateStatus', 'hold');
    dsScan($this, $d['id'], $codes[1])->assertCreated();
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 76])->assertCreated()->assertJsonPath('dispatch.gateStatus', 'pass');
    expect(DB::table('exceptions')->where('kind', 'gate_variance')->value('status'))->toBe('resolved');
});

it('keeps the weigher from overriding their own weighing', function (): void {
    $codes = dsBundles($this, 1);
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 1]])->json('dispatch');
    dsScan($this, $d['id'], $codes[0]);
    $this->withToken($this->sm)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 50])->assertCreated()->assertJsonPath('dispatch.gateStatus', 'hold');
    $this->withToken($this->sm)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/override", ['reason' => 'I trust the scale'])->assertForbidden()->assertJsonPath('code', 'forbidden');
    expect(DB::table('dispatches')->value('gate_status'))->toBe('hold')->and(DB::table('delivery_notes')->count())->toBe(0);
});

it('enforces the scan rules', function (): void {
    [$a, $b, $c] = dsBundles($this, 3);
    [$x] = dsBundles($this, 1, $this->other);
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 2]])->json('dispatch');
    dsScan($this, $d['id'], $a)->assertCreated();
    dsScan($this, $d['id'], $a)->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    dsScan($this, $d['id'], $x)->assertStatus(422);
    dsScan($this, $d['id'], 'NOPE-1')->assertStatus(422);
    dsScan($this, $d['id'], $b)->assertCreated();
    dsScan($this, $d['id'], $c)->assertStatus(409);                      // line cap
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/unscan", ['code' => $b])->assertCreated()
        ->assertJsonPath('dispatch.scannedCodes', [$a]);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/unscan", ['code' => $b])->assertStatus(409);

    // A label loaded on another lorry, or already dispatched, cannot be scanned again.
    $e = dsStart($this, [['productId' => $this->mat, 'bundles' => 2]], 'SO-2302')->json('dispatch');
    dsScan($this, $e['id'], $a)->assertStatus(409);
    DB::table('labels')->where('code', $c)->update(['status' => 'dispatched']);
    dsScan($this, $e['id'], $c)->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    // Offline envelope works for scans too, and rejects as data.
    expect(dsEnvelope($this, $this->store, 'dispatch.bundle_scanned', ['dispatch_id' => $e['id'], 'code' => $b])->json('results.0.status'))->toBe('accepted')
        ->and(dsEnvelope($this, $this->store, 'dispatch.bundle_scanned', ['dispatch_id' => $e['id'], 'code' => $b])->json('results.0.code'))->toBe('state_conflict');
});

it('validates the order snapshot against the site', function (): void {
    dsStart($this, [['productId' => $this->bagsProduct, 'bundles' => 1]])->assertStatus(422)->assertJson(['details' => ['errors' => ['order.lines.0.product_id' => 'is not an active product of this site']]]);
    dsStart($this, [['productId' => $this->mat, 'bundles' => 0]])->assertStatus(422);
    dsStart($this, [['productId' => $this->mat, 'bundles' => 1], ['productId' => $this->mat, 'bundles' => 2]])->assertStatus(422);
    dsStart($this, [])->assertStatus(422);
    $this->withToken($this->store)->postJson('/api/v1/sites/mats/dispatches', ['driver' => 'A', 'vehicle' => 'B', 'order' => ['orderRef' => 'X', 'clientName' => 'Y', 'site' => 'bags',
        'lines' => [['productId' => $this->mat, 'bundles' => 1]]]])->assertStatus(422);
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 2], ['productId' => $this->other, 'bundles' => 1]])->assertCreated()->json('dispatch');
    expect($d['expectedKg'])->toEqual(101.2);
    dsStart($this, [['productId' => $this->mat, 'bundles' => 1]])->assertStatus(409);  // order already has an open dispatch
    $id = (string) Str::uuid7();
    expect(dsEnvelope($this, $this->store, 'dispatch.started', ['dispatch_id' => $id, 'driver' => 'A', 'vehicle' => 'B', 'order' => ['order_ref' => 'SO-9', 'client_name' => 'C', 'site' => 'mats', 'lines' => [['product_id' => $this->mat, 'bundles' => 1]]]])->json('results.0.status'))->toBe('accepted');
    expect(dsEnvelope($this, $this->store, 'dispatch.started', ['dispatch_id' => $id, 'driver' => 'A', 'vehicle' => 'B', 'order' => ['order_ref' => 'SO-10', 'client_name' => 'C', 'site' => 'mats', 'lines' => [['product_id' => $this->mat, 'bundles' => 1]]]])->json('results.0.code'))->toBe('duplicate_fact');
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 0])->assertStatus(422);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 2e7])->assertStatus(422);
});

it('scopes reads and writes to the site and its roles', function (): void {
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 1]])->json('dispatch');
    $this->withToken($this->otherStore)->getJson('/api/v1/sites/mats/dispatches')->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/dispatches')->assertForbidden();
    $this->withToken($this->sup)->getJson('/api/v1/sites/mats/dispatches')->assertOk()->assertJsonCount(1, 'dispatches');
    $this->withToken($this->ceo)->getJson("/api/v1/sites/mats/dispatches/{$d['id']}")->assertOk();
    dsStart($this, [['productId' => $this->mat, 'bundles' => 1]], 'SO-3', $this->otherStore)->assertForbidden();
    dsStart($this, [['productId' => $this->mat, 'bundles' => 1]], 'SO-4', $this->sup)->assertForbidden();
    dsStart($this, [['productId' => $this->mat, 'bundles' => 1]], 'SO-5', $this->ceo)->assertForbidden();
    $this->withToken($this->otherStore)->postJson("/api/v1/sites/bags/dispatches/{$d['id']}/scan", ['code' => 'X'])->assertNotFound();
    $this->withToken($this->otherStore)->getJson('/api/v1/sites/mats/snapshots/dispatches')->assertForbidden();
});

it('freezes loaded labels: no consume or scrap while open, and the gate rejects a label that left stock', function (): void {
    $codes = dsBundles($this, 2);
    $d = dsStart($this, [['productId' => $this->mat, 'bundles' => 2]])->json('dispatch');
    foreach ($codes as $c) {
        dsScan($this, $d['id'], $c)->assertCreated();
    }
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$codes[0]}/consume", ['consumedByStage' => 'finishing'])
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict')->assertJsonFragment(['detail' => "Label {$codes[0]} is loaded on dispatch SO-2301; unscan it first."]);
    $this->withToken($this->sm)->postJson("/api/v1/sites/mats/stock/labels/{$codes[0]}/scrap", ['reason' => 'torn'])
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict');

    // A label that left stock by any other path (simulated) blocks the pass and the override; nothing is emitted.
    DB::table('labels')->where('code', $codes[1])->update(['status' => 'consumed']);
    $before = DB::table('events')->count();
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/dispatches/{$d['id']}/gate-weight", ['kg' => 76])
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    expect(DB::table('events')->count())->toBe($before)
        ->and(DB::table('labels')->where('code', $codes[0])->value('status'))->toBe('in_stock')
        ->and((int) DB::table('number_series')->where(['site_id' => $this->mats, 'kind' => 'delivery_note'])->value('next'))->toBe(4817);
});

it('lets the site driver read dispatches and snapshots', function (): void {
    dsStart($this, [['productId' => $this->mat, 'bundles' => 1]])->assertCreated();
    $driver = User::query()->create(['name' => 'Driver', 'username' => 'drvmats', 'password' => 'password']);
    DB::table('user_site_roles')->insert(['id' => (string) Str::uuid7(), 'user_id' => $driver->id, 'site_id' => $this->mats, 'role' => 'driver']);
    $t = $driver->createToken('office', ['user'])->plainTextToken;
    $this->withToken($t)->getJson('/api/v1/sites/mats/dispatches')->assertOk()->assertJsonCount(1, 'dispatches');
    $this->withToken($t)->getJson('/api/v1/sites/mats/snapshots/dispatches')->assertOk();
    dsStart($this, [['productId' => $this->mat, 'bundles' => 1]], 'SO-9', $t)->assertForbidden();
});
