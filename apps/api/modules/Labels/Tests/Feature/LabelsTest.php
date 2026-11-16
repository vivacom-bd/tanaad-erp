<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Labels\Models\Label;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->sup = $token('u_sup_mats');
    $this->store = $token('u_store_mats');
    $this->ceo = $token('u_ceo1');
    $this->cashier = $token('u_cash_mats');
    $this->otherSup = $token('u_sup_bags');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    foreach ([['bags', 'extrude'], ['mats', 'finishing'], ['mats', 'weaving']] as [$site, $stage]) {
        DB::table('stages')->insert(['id' => (string) Str::uuid7(), 'site_id' => Site::query()->where('code', $site)->value('id'), 'code' => $stage, 'name' => ucfirst($stage),
            'sequence' => 1, 'input_unit' => 'pcs', 'output_unit' => 'pcs', 'produces_label' => 'bundle']);
    }
    $this->product = (string) Str::uuid7();
    DB::table('products')->insert([
        'id' => $this->product, 'site_id' => $this->mats, 'code' => 'p_test', 'internal_name' => 'Test mat', 'commercial_name' => 'Test mat 90', 'kind' => 'mat',
        'bundle_pieces' => 50, 'std_bundle_weight_kg' => 20, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed',
        'seq' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function bundle(Tests\TestCase $t, string $token, array $over = []): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/labels', [
        'kind' => 'bundle', 'stageCode' => 'finishing', 'productId' => $t->product, 'qtyUnits' => 50, 'qtyKg' => 20.25, ...$over,
    ]);
}

function labelRows(): array
{
    $strip = fn ($rows) => $rows->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at'], $a['id']);

        return $a;
    })->all();

    return ['labels' => $strip(DB::table('labels')->orderBy('code')->get()), 'history' => $strip(DB::table('label_history')->orderBy('seq')->orderBy('type')->get())];
}

it('prints a bundle at finishing, counts it in stock, and consumes it', function (): void {
    $r = bundle($this, $this->sup)->assertCreated();
    $code = $r->json('label.code');
    expect($code)->toMatch('/^MAT-BUN-\d{6}$/')->and($r->json('label.status'))->toBe('in_stock');

    $stock = $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/label-stock')->assertOk()->json('stock');
    expect($stock)->toHaveCount(1)->and($stock[0]['bundles'])->toBe(1)->and($stock[0]['pieces'])->toEqual(50)->and($stock[0]['kg'])->toEqual(20.25)
        ->and($stock[0]['product']['id'])->toBe($this->product);

    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$code}/consume", ['consumedByStage' => 'dispatch', 'consumedRef' => 'DN-1'])
        ->assertCreated()->assertJsonPath('label.status', 'consumed');
    expect($this->withToken($this->ceo)->getJson('/api/v1/sites/mats/label-stock')->json('stock'))->toBe([]);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/labels/{$code}/consume", [])->assertStatus(409)->assertJsonPath('code', 'state_conflict');

    $h = $this->withToken($this->ceo)->getJson("/api/v1/labels/{$code}/history")->assertOk();
    expect(array_column($h->json('history'), 'type'))->toBe(['created', 'consumed'])
        ->and($h->json('product.id'))->toBe($this->product)->and($h->json('label.status'))->toBe('consumed');
});

it('numbers codes per kind in sequence, honouring a defined series, with parents resolved', function (): void {
    $a = bundle($this, $this->sup)->json('label');
    $b = bundle($this, $this->sup)->json('label');
    expect((int) substr($b['code'], -6))->toBe((int) substr($a['code'], -6) + 1);
    $roll = bundle($this, $this->sup, ['kind' => 'roll', 'stageCode' => 'weaving'])->assertCreated()->json('label');
    expect($roll['code'])->toStartWith('MAT-ROL-');

    app(Tanaad\Shared\Ledger\NumberSeries::class)->define($this->mats, 'label_sack', 'SK', 4, 7);
    expect(bundle($this, $this->sup, ['kind' => 'sack'])->json('label.code'))->toBe('SK-0007');

    $child = bundle($this, $this->sup, ['parentCodes' => [$roll['code']], 'parentIds' => [$a['id']]])->assertCreated();
    expect($child->json('label.parentIds'))->toEqualCanonicalizing([$roll['id'], $a['id']]);
    $h = $this->withToken($this->sup)->getJson('/api/v1/labels/'.$child->json('label.code').'/history')->assertOk();
    expect($h->json('parents'))->toHaveCount(2);
    bundle($this, $this->sup, ['parentCodes' => ['NOPE']])->assertStatus(422);
});

it('creates a label as created when asked, which is not in stock and cannot be consumed', function (): void {
    $r = bundle($this, $this->sup, ['kind' => 'roll', 'status' => 'created'])->assertCreated();
    expect($this->withToken($this->ceo)->getJson('/api/v1/sites/mats/label-stock')->json('stock'))->toBe([]);
    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/labels/'.$r->json('label.id').'/consume', [])->assertStatus(409);
    $list = $this->withToken($this->sup)->getJson('/api/v1/sites/mats/labels?status=created&kind=roll')->assertOk();
    expect($list->json('total'))->toBe(1);
    $this->withToken($this->sup)->getJson('/api/v1/sites/mats/labels?status=bogus')->assertStatus(422);
});

it('rejects a duplicate label id and invalid payloads', function (): void {
    $id = (string) Str::uuid7();
    bundle($this, $this->sup, ['labelId' => $id])->assertCreated();
    bundle($this, $this->sup, ['labelId' => $id])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    bundle($this, $this->sup, ['kind' => 'pallet'])->assertStatus(422);
    bundle($this, $this->sup, ['productId' => (string) Str::uuid7()])->assertStatus(422);
    bundle($this, $this->sup, ['qtyKg' => -1])->assertStatus(422);
});

it('enforces site and role scoping', function (): void {
    $code = bundle($this, $this->sup)->json('label.code');
    bundle($this, $this->cashier)->assertForbidden();
    bundle($this, $this->ceo)->assertForbidden();
    bundle($this, $this->otherSup)->assertForbidden();
    $this->withToken($this->otherSup)->postJson("/api/v1/sites/mats/labels/{$code}/consume", [])->assertForbidden();
    $this->withToken($this->otherSup)->getJson('/api/v1/sites/mats/label-stock')->assertForbidden();
    $this->withToken($this->otherSup)->getJson('/api/v1/sites/mats/labels')->assertForbidden();
    $this->withToken($this->otherSup)->getJson("/api/v1/labels/{$code}/history")->assertForbidden();
    $this->withToken($this->cashier)->getJson("/api/v1/labels/{$code}/history")->assertForbidden();
    $this->withToken($this->ceo)->getJson('/api/v1/labels/NOPE-1/history')->assertNotFound();
});

it('accepts the offline envelope idempotently and reports the real code', function (): void {
    $id = (string) Str::uuid7();
    $event = [
        'id' => $id, 'type' => 'labels.created', 'occurred_at' => CarbonImmutable::now()->toIso8601String(), 'provisional_ref' => 'tmp-L1',
        'payload' => ['label_id' => (string) Str::uuid7(), 'kind' => 'bundle', 'stage_code' => 'finishing', 'product_id' => $this->product, 'qty_units' => 50, 'qty_kg' => 20],
    ];
    $send = fn () => $this->withToken($this->sup)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$event]]);
    expect($send()->assertOk()->json('results.0.status'))->toBe('accepted');
    expect($send()->assertOk()->json('results.0.status'))->toBe('duplicate')
        ->and(Label::query()->count())->toBe(1);

    $consume = ['id' => (string) Str::uuid7(), 'type' => 'labels.consumed', 'occurred_at' => CarbonImmutable::now()->toIso8601String(), 'payload' => ['code' => Label::query()->firstOrFail()->code]];
    $r = $this->withToken($this->sup)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$consume, [...$consume, 'id' => (string) Str::uuid7()]]])->assertOk();
    expect($r->json('results.0.status'))->toBe('accepted')->and($r->json('results.1.code'))->toBe('state_conflict');
    $snap = $this->withToken($this->sup)->getJson('/api/v1/sites/mats/snapshots/labels?since=0')->assertOk();
    expect($snap->json('data'))->toHaveCount(1)->and($snap->json('data.0.status'))->toBe('consumed');
});

it('appends stock.* and dispatch.* events to the history and keeps codes after Replay', function (): void {
    $r = bundle($this, $this->sup, ['zoneId' => null])->assertCreated();
    $code = $r->json('label.code');
    $id = $r->json('label.id');
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/labels/{$id}/consume", ['consumedByStage' => 'x'])->assertCreated();
    $b = bundle($this, $this->sup)->json('label');

    $stored = app(EventStore::class)->append([new Event(
        id: (string) Str::uuid7(), type: 'dispatch.label_dispatched', siteId: $this->mats, actorId: null, deviceId: null,
        occurredAt: CarbonImmutable::now(), payload: ['code' => $b['code'], 'dispatch_id' => (string) Str::uuid7()],
    )]);
    app(CommandBus::class)->project($stored[0]);
    expect(Label::query()->where('code', $b['code'])->value('status'))->toBe('dispatched')
        ->and(DB::table('label_history')->where('label_id', $b['id'])->pluck('type')->all())->toBe(['created', 'dispatched']);

    $before = labelRows();
    app(Replay::class)->rebuild();
    expect(labelRows())->toEqual($before)->and(Label::query()->where('id', $id)->value('code'))->toBe($code);
});

it('requires the product and stage to belong to the site and the operator to exist', function (): void {
    $bags = (string) Site::query()->where('code', 'bags')->value('id');
    $foreign = (string) Str::uuid7();
    DB::table('products')->insert([
        'id' => $foreign, 'site_id' => $bags, 'code' => 'p_bag', 'internal_name' => 'Bag', 'commercial_name' => 'Bag', 'kind' => 'mat',
        'bundle_pieces' => 50, 'std_bundle_weight_kg' => 20, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed',
        'seq' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    bundle($this, $this->sup, ['productId' => $foreign])->assertStatus(422)->assertJsonPath('details.errors.product_id', 'not found');
    bundle($this, $this->sup, ['stageCode' => 'nowhere'])->assertStatus(422)->assertJsonPath('details.errors.stage_code', 'no such stage at this site');
    bundle($this, $this->sup, ['operatorId' => (string) Str::uuid7()])->assertStatus(422)->assertJsonPath('details.errors.operator_id', 'not found');
    bundle($this, $this->sup, ['qtyKg' => 1e12])->assertStatus(422)->assertJsonPath('details.errors.qty_kg', 'must be at most 10000000');
    expect(Label::query()->count())->toBe(0);
});

it('dates a consumption in the site timezone (night shift past midnight)', function (): void {
    $code = bundle($this, $this->sup)->assertCreated()->json('label.code');
    $consume = ['id' => (string) Str::uuid7(), 'type' => 'labels.consumed', 'occurred_at' => '2026-11-09T22:30:00Z', 'payload' => ['code' => $code]];
    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$consume]])->assertOk()
        ->assertJsonPath('results.0.status', 'accepted');
    $ev = json_decode((string) DB::table('events')->where('type', 'labels.consumed')->value('payload'), true);
    expect($ev['day'])->toBe('2026-11-10');
});

it('keeps label codes unique per site, not globally', function (): void {
    $code = bundle($this, $this->sup)->assertCreated()->json('label.code');
    $bags = (string) Site::query()->where('code', 'bags')->value('id');
    $row = (array) DB::table('labels')->where('code', $code)->first();
    DB::table('labels')->insert([...$row, 'id' => (string) Str::uuid7(), 'site_id' => $bags]);
    expect(fn () => DB::table('labels')->insert([...$row, 'id' => (string) Str::uuid7()]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
    $this->withToken($this->ceo)->getJson("/api/v1/labels/{$code}/history")->assertStatus(422);
    $this->withToken($this->ceo)->getJson("/api/v1/labels/{$code}/history?site=mats")->assertOk();
});
