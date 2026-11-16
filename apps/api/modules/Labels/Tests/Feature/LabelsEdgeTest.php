<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Labels\Models\Label;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->sup = $token('u_sup_mats');
    $this->store = $token('u_store_mats');
    $this->ceo = $token('u_ceo1');
    $this->cashier = $token('u_cash_mats');
    $this->otherSup = $token('u_sup_bags');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->bags = (string) Site::query()->where('code', 'bags')->value('id');
    $this->product = (string) Str::uuid7();
    DB::table('products')->insert([
        'id' => $this->product, 'site_id' => $this->mats, 'code' => 'p_test', 'internal_name' => 'Test mat', 'commercial_name' => 'Test mat 90', 'kind' => 'mat',
        'bundle_pieces' => 50, 'std_bundle_weight_kg' => 20, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed',
        'seq' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->bagsProduct = (string) Str::uuid7();
    DB::table('products')->insert([
        'id' => $this->bagsProduct, 'site_id' => $this->bags, 'code' => 'p_bag_test', 'internal_name' => 'Test bag', 'commercial_name' => 'Test bag', 'kind' => 'bag',
        'bundle_pieces' => 50, 'std_bundle_weight_kg' => 20, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed',
        'seq' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function edgeLabel(Tests\TestCase $t, string $token, array $over = [], string $site = 'mats'): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/labels", [
        'kind' => 'bundle', 'stageCode' => $site === 'bags' ? 'extrude' : 'finish', 'productId' => $t->product, 'qtyUnits' => 50, 'qtyKg' => 20.25, ...$over,
    ]);
}

function edgeLabelRows(): array
{
    $strip = fn ($rows) => $rows->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at'], $a['id']);

        return $a;
    })->all();

    return ['labels' => $strip(DB::table('labels')->orderBy('code')->get()), 'history' => $strip(DB::table('label_history')->orderBy('seq')->orderBy('type')->get())];
}

it('rejects malformed numbers, kinds, dates and statuses with field errors', function (): void {
    foreach ([-1, 'abc', 'NaN', [], true] as $bad) {
        edgeLabel($this, $this->sup, ['qtyKg' => $bad])->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
        edgeLabel($this, $this->sup, ['qtyUnits' => $bad])->assertStatus(422);
    }
    edgeLabel($this, $this->sup, ['kind' => null])->assertStatus(422);
    edgeLabel($this, $this->sup, ['kind' => 'BUNDLE'])->assertStatus(422);
    edgeLabel($this, $this->sup, ['kind' => 5])->assertStatus(422);
    edgeLabel($this, $this->sup, ['stageCode' => ''])->assertStatus(422);
    edgeLabel($this, $this->sup, ['stageCode' => str_repeat('x', 33)])->assertStatus(422);
    edgeLabel($this, $this->sup, ['status' => 'consumed'])->assertStatus(422);
    edgeLabel($this, $this->sup, ['day' => '2026-02-30'])->assertStatus(422);
    edgeLabel($this, $this->sup, ['day' => '10/11/2026'])->assertStatus(422);
    edgeLabel($this, $this->sup, ['labelId' => 'not-a-uuid'])->assertStatus(422);
    edgeLabel($this, $this->sup, ['parentIds' => 'x'])->assertStatus(422);
    edgeLabel($this, $this->sup, ['parentIds' => ['key' => 'v']])->assertStatus(422);
    edgeLabel($this, $this->sup, ['parentIds' => [(string) Str::uuid7()]])->assertStatus(422);
    expect(Label::query()->count())->toBe(0);

    $err = edgeLabel($this, $this->sup, ['kind' => 'pallet', 'qtyKg' => -2])->assertStatus(422)->json();
    expect(json_encode($err))->toContain('kind')->toContain('qty_kg');
});

it('rejects unknown ids and ids that belong to another site', function (): void {
    foreach (['productId', 'machineId', 'operatorId', 'zoneId'] as $field) {
        edgeLabel($this, $this->sup, [$field => (string) Str::uuid7()])->assertStatus(422);
    }
    $bagsZone = (string) DB::table('zones')->where('site_id', $this->bags)->value('id');
    $bagsMachine = (string) DB::table('machines')->where('site_id', $this->bags)->value('id');
    if ($bagsZone !== '') {
        edgeLabel($this, $this->sup, ['zoneId' => $bagsZone])->assertStatus(422);
    }
    if ($bagsMachine !== '') {
        edgeLabel($this, $this->sup, ['machineId' => $bagsMachine])->assertStatus(422);
    }
    // A bags label cannot be a parent of a mats label.
    $bagsLabel = edgeLabel($this, $this->otherSup, ['productId' => $this->bagsProduct], 'bags')->assertCreated()->json('label');
    edgeLabel($this, $this->sup, ['parentIds' => [$bagsLabel['id']]])->assertStatus(422);
    edgeLabel($this, $this->sup, ['parentCodes' => [$bagsLabel['code']]])->assertStatus(422);
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/labels/{$bagsLabel['id']}/consume", [])->assertNotFound();
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/labels/{$bagsLabel['code']}/consume", [])->assertNotFound();
    expect(Label::query()->where('id', $bagsLabel['id'])->value('status'))->toBe('in_stock');
});

it('rejects a product of another site', function (): void {
    edgeLabel($this, $this->sup, ['productId' => $this->bagsProduct])->assertStatus(422);
});

it('rejects non-finite and over-wide quantities with 422, not 500', function (): void {
    edgeLabel($this, $this->sup, ['qtyKg' => '1e999'])->assertStatus(422);
});

it('rejects a quantity wider than the column with 422, not 500', function (): void {
    edgeLabel($this, $this->sup, ['qtyKg' => 1e12])->assertStatus(422);
});

it('accepts decimal quantities and rounds them half-up to three places', function (): void {
    $r = edgeLabel($this, $this->sup, ['qtyKg' => 20.0005, 'qtyUnits' => '12.3456'])->assertCreated();
    expect($r->json('label.qtyKg'))->toEqual(20.001)->and($r->json('label.qtyUnits'))->toEqual(12.346);
    $z = edgeLabel($this, $this->sup, ['qtyKg' => 0, 'qtyUnits' => 0])->assertCreated();
    expect($z->json('label.qtyKg'))->toEqual(0);
    $none = $this->withToken($this->sup)->postJson('/api/v1/sites/mats/labels', ['kind' => 'pack', 'stageCode' => 'finish'])->assertCreated();
    expect($none->json('label.qtyKg'))->toEqual(0);
});

it('numbers codes per kind and per site independently', function (): void {
    $n = fn (string $code): int => (int) substr($code, -6);
    $b1 = edgeLabel($this, $this->sup)->json('label.code');
    $r1 = edgeLabel($this, $this->sup, ['kind' => 'roll'])->json('label.code');
    $b2 = edgeLabel($this, $this->sup)->json('label.code');
    $r2 = edgeLabel($this, $this->sup, ['kind' => 'roll'])->json('label.code');
    expect($b1)->toBe('MAT-BUN-000001')->and($b2)->toBe('MAT-BUN-000002')->and($r1)->toBe('MAT-ROL-000001')->and($r2)->toBe('MAT-ROL-000002');

    $bagB = edgeLabel($this, $this->otherSup, ['productId' => $this->bagsProduct], 'bags')->assertCreated()->json('label.code');
    $bagR = edgeLabel($this, $this->otherSup, ['kind' => 'roll', 'productId' => $this->bagsProduct], 'bags')->json('label.code');
    expect($bagB)->toBe('BAG-BUN-000001')->and($bagR)->toBe('BAG-ROL-000001');
    expect($n(edgeLabel($this, $this->sup)->json('label.code')))->toBe(3);
    expect(Label::query()->distinct()->count('code'))->toBe(Label::query()->count());
});

it('rejects duplicate label ids even across sites and failed creates burn no code', function (): void {
    $id = (string) Str::uuid7();
    edgeLabel($this, $this->sup, ['labelId' => $id])->assertCreated();
    edgeLabel($this, $this->otherSup, ['labelId' => $id, 'productId' => $this->bagsProduct], 'bags')->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    edgeLabel($this, $this->sup, ['kind' => 'pallet'])->assertStatus(422);
    expect(edgeLabel($this, $this->sup)->json('label.code'))->toBe('MAT-BUN-000002');
});

it('conflicts on consume twice, by id and by code, and on consuming unknown labels', function (): void {
    $l = edgeLabel($this, $this->sup)->json('label');
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$l['id']}/consume", [])->assertCreated();
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$l['id']}/consume", [])->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$l['code']}/consume", [])->assertStatus(409);
    $this->withToken($this->store)->postJson('/api/v1/sites/mats/labels/'.Str::uuid7().'/consume', [])->assertNotFound();
    $this->withToken($this->store)->postJson('/api/v1/sites/mats/labels/NOPE-000001/consume', [])->assertNotFound();
    $fresh = edgeLabel($this, $this->sup)->json('label');
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$fresh['id']}/consume", ['consumedRef' => str_repeat('x', 121)])->assertStatus(422);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$fresh['id']}/consume", ['consumedByStage' => str_repeat('x', 33)])->assertStatus(422);
    expect(Label::query()->where('id', $fresh['id'])->value('status'))->toBe('in_stock');
});

it('does not consume a dispatched or scrapped label', function (): void {
    $l = edgeLabel($this, $this->sup)->json('label');
    DB::table('labels')->where('id', $l['id'])->update(['status' => 'dispatched']);
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/labels/{$l['id']}/consume", [])->assertStatus(409);
    DB::table('labels')->where('id', $l['id'])->update(['status' => 'scrapped']);
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/labels/{$l['id']}/consume", [])->assertStatus(409);
});

it('enforces role and site on every endpoint', function (): void {
    $l = edgeLabel($this, $this->sup)->json('label');
    $mgr = User::query()->where('code', 'u_sm_mats')->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    edgeLabel($this, $mgr)->assertCreated();
    edgeLabel($this, $this->store)->assertCreated();
    foreach ([$this->cashier, $this->ceo, $this->otherSup] as $t) {
        edgeLabel($this, $t)->assertForbidden();
        $this->withToken($t)->postJson("/api/v1/sites/mats/labels/{$l['id']}/consume", [])->assertForbidden();
    }
    foreach (['/api/v1/sites/mats/labels', '/api/v1/sites/mats/label-stock'] as $url) {
        $this->withToken($this->otherSup)->getJson($url)->assertForbidden();
        $this->withToken($this->cashier)->getJson($url)->assertForbidden();
        $this->withToken($this->ceo)->getJson($url)->assertOk();
    }
    $this->withToken($this->otherSup)->getJson('/api/v1/sites/mats/snapshots/labels?since=0')->assertForbidden();
    $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/snapshots/labels?since=0')->assertOk();
    $this->withToken($this->sup)->getJson('/api/v1/sites/bags/labels')->assertForbidden();
    $this->withToken($this->sup)->getJson('/api/v1/sites/bags/label-stock')->assertForbidden();
    edgeLabel($this, $this->sup, ['productId' => $this->bagsProduct], 'bags')->assertForbidden();
    $this->withToken($this->sup)->getJson('/api/v1/sites/nowhere/labels')->assertStatus(404);
    $this->flushHeaders()->getJson('/api/v1/sites/mats/labels')->assertUnauthorized();
    $this->postJson('/api/v1/sites/mats/labels', [])->assertUnauthorized();
    expect(Label::query()->where('site_id', $this->bags)->count())->toBe(0);
});

it('keeps the labels snapshot to roles that may read labels', function (): void {
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/snapshots/labels?since=0')->assertForbidden();
});

it('validates list filters and paging', function (): void {
    edgeLabel($this, $this->sup)->assertCreated();
    foreach (['kind=pallet', 'status=nope', 'day=yesterday', 'day=2026-13-01x'] as $q) {
        $this->withToken($this->sup)->getJson("/api/v1/sites/mats/labels?{$q}")->assertStatus(422);
    }
    $r = $this->withToken($this->sup)->getJson('/api/v1/sites/mats/labels?perPage=0&page=-4')->assertOk();
    expect($r->json('page'))->toBe(1)->and($r->json('perPage'))->toBe(1)->and($r->json('total'))->toBe(1);
    expect($this->withToken($this->sup)->getJson('/api/v1/sites/mats/labels?perPage=9999')->json('perPage'))->toBe(200);
    expect($this->withToken($this->sup)->getJson('/api/v1/sites/mats/labels?page=5')->json('data'))->toBe([]);
    $this->withToken($this->sup)->getJson('/api/v1/labels/'.Str::uuid7().'/history')->assertNotFound();
});

it('handles an offline batch mixing valid, invalid, duplicate and conflicting label events', function (): void {
    $now = CarbonImmutable::now()->toIso8601String();
    $create = fn (array $p = [], ?string $id = null) => ['id' => $id ?? (string) Str::uuid7(), 'type' => 'labels.created', 'occurred_at' => $now,
        'payload' => ['label_id' => (string) Str::uuid7(), 'kind' => 'bundle', 'stage_code' => 'finish', 'product_id' => $this->product, 'qty_units' => 5, 'qty_kg' => 2, ...$p]];
    $good = $create();
    $sameLabelDifferentEvent = $create(['label_id' => $good['payload']['label_id']]);
    $badKind = $create(['kind' => 'pallet']);
    $badQty = $create(['qty_kg' => -3]);
    $unknownProduct = $create(['product_id' => (string) Str::uuid7()]);
    $consumeUnknown = ['id' => (string) Str::uuid7(), 'type' => 'labels.consumed', 'occurred_at' => $now, 'payload' => ['code' => 'NOPE-1']];
    $consumeNothing = ['id' => (string) Str::uuid7(), 'type' => 'labels.consumed', 'occurred_at' => $now, 'payload' => []];
    $unknownType = ['id' => (string) Str::uuid7(), 'type' => 'labels.exploded', 'occurred_at' => $now, 'payload' => []];
    $r = $this->withToken($this->sup)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(),
        'events' => [$good, $good, $sameLabelDifferentEvent, $badKind, $badQty, $unknownProduct, $consumeUnknown, $consumeNothing, $unknownType]])->assertOk();
    $s = array_column($r->json('results'), 'status');
    expect($s[0])->toBe('accepted')->and($s[1])->toBe('duplicate')->and($s[2])->toBe('rejected')->and($r->json('results.2.code'))->toBe('duplicate_fact')
        ->and(array_slice($s, 3))->each->toBe('rejected')
        ->and($r->json('results.3.code'))->toBe('invalid_payload')->and($r->json('results.6.code'))->toBe('not_found')->and($r->json('results.7.code'))->toBe('invalid_payload');
    expect(Label::query()->count())->toBe(1);
});

it('rejects an offline envelope from a user of another site or wrong role, event by event', function (): void {
    $ev = ['id' => (string) Str::uuid7(), 'type' => 'labels.created', 'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        'payload' => ['label_id' => (string) Str::uuid7(), 'kind' => 'bundle', 'stage_code' => 'finish', 'product_id' => $this->product]];
    foreach ([$this->otherSup, $this->cashier, $this->ceo] as $t) {
        $r = $this->withToken($t)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [[...$ev, 'id' => (string) Str::uuid7()]]]);
        expect($r->status() === 403 || $r->json('results.0.status') === 'rejected')->toBeTrue();
    }
    expect(Label::query()->count())->toBe(0);
});

it('keeps codes, statuses and history exact after Replay following edge cases', function (): void {
    $a = edgeLabel($this, $this->sup)->json('label');
    edgeLabel($this, $this->sup, ['kind' => 'pallet'])->assertStatus(422);
    edgeLabel($this, $this->sup, ['labelId' => $a['id']])->assertStatus(409);
    $b = edgeLabel($this, $this->sup, ['kind' => 'roll', 'parentIds' => [$a['id']], 'qtyKg' => 3.0005])->json('label');
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/labels/{$a['id']}/consume", ['consumedRef' => 'X'])->assertCreated();
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/labels/{$a['id']}/consume", [])->assertStatus(409);
    $c = edgeLabel($this, $this->otherSup, ['productId' => $this->bagsProduct], 'bags')->json('label');

    $before = edgeLabelRows();
    app(Replay::class)->rebuild();
    expect(edgeLabelRows())->toEqual($before)
        ->and(Label::query()->where('id', $b['id'])->value('code'))->toBe($b['code'])
        ->and(Label::query()->where('id', $c['id'])->value('code'))->toBe($c['code']);
    app(Replay::class)->rebuild();
    expect(edgeLabelRows())->toEqual($before);
});
