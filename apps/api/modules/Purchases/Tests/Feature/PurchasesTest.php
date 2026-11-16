<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00:00', 'Africa/Kampala'));
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->store = $token('u_store_mats');
    $this->manager = $token('u_sm_mats');
    $this->cashier = $token('u_cash_mats');
    $this->supervisor = $token('u_sup_mats');
    $this->ceo = $token('u_ceo1');
    $this->admin = $token('u_admin');
    $this->bagsManager = $token('u_sm_bags');
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/number-series', ['kind' => 'purchase_order', 'prefix' => 'PO', 'pad' => 4])->assertCreated();
    $this->withToken($this->admin)->postJson('/api/v1/fx', ['day' => '2026-11-10', 'rate' => 3700])->assertCreated();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function order(Tests\TestCase $t, string $token, array $body = []): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/purchases', [
        'supplier' => 'Polymers Ltd', 'originCountry' => 'KE', 'material' => 'HDPE resin', 'qtyKg' => 25500, 'unitPrice' => 1.1, 'currency' => 'USD',
        'costs' => [['label' => 'Freight', 'amount' => 2400, 'currency' => 'USD'], ['label' => 'Clearing', 'amount' => 3500000, 'currency' => 'UGX']], ...$body,
    ]);
}

/** @return array<string, mixed> */
function purchaseRows(): array
{
    return DB::table('purchases')->orderBy('id')->get()->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();
}

it('allocates the PO reference and captures fx server-side', function (): void {
    $r = order($this, $this->store, ['reference' => 'PO-9999', 'fxReference' => 1])->assertCreated();
    expect($r->json('purchase.reference'))->toBe('PO-0001')->and($r->json('purchase.fxReference'))->toEqual(3700)
        ->and($r->json('purchase.status'))->toBe('ordered')->and($r->json('purchase.orderedOn'))->toBe('2026-11-10');
    expect(order($this, $this->store, ['currency' => 'UGX', 'unitPrice' => 4000, 'costs' => []])->json('purchase.reference'))->toBe('PO-0002');
    $payload = json_decode((string) DB::table('events')->where('type', 'purchases.recorded')->orderBy('seq')->value('payload'), true);
    expect($payload)->toMatchArray(['reference' => 'PO-0001', 'fx_reference' => 3700]);
});

it('edits before receipt with the same id, keeping reference and fx', function (): void {
    $p = order($this, $this->store)->json('purchase');
    $this->withToken($this->admin)->postJson('/api/v1/fx', ['day' => '2026-11-10', 'rate' => 3800])->assertCreated();
    $r = $this->withToken($this->manager)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/change", [
        'supplier' => 'Polymers Ltd', 'material' => 'HDPE resin', 'qtyKg' => 26000, 'unitPrice' => 1.2, 'currency' => 'USD', 'costs' => [], 'status' => 'in_transit',
    ])->assertCreated();
    expect($r->json('purchase'))->toMatchArray(['id' => $p['id'], 'reference' => 'PO-0001', 'status' => 'in_transit', 'qtyKg' => 26000])
        ->and($r->json('purchase.fxReference'))->toEqual(3700);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/purchases/'.Str::uuid7().'/change', [
        'supplier' => 'x', 'material' => 'y', 'qtyKg' => 1, 'unitPrice' => 1, 'currency' => 'UGX', 'status' => 'ordered',
    ])->assertNotFound();
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/change", [
        'supplier' => 'x', 'material' => 'y', 'qtyKg' => 1, 'unitPrice' => 1, 'currency' => 'UGX', 'status' => 'received',
    ])->assertStatus(422);
});

it('receives once with landed cost from the shared fixture and flags a short receipt', function (): void {
    $f = json_decode((string) file_get_contents(base_path('../../contracts/fixtures/arithmetic.json')), true)['landedCost'][0];
    $p = order($this, $this->store)->json('purchase');
    $r = $this->withToken($this->store)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/receive", ['receivedKg' => 25500])->assertCreated();
    expect($r->json('purchase.landed.perKgUgx'))->toEqual($f['expected']['perKgUgx'])
        ->and($r->json('purchase.landed.totalUgx'))->toEqual($f['expected']['totalUgx'])
        ->and($r->json('purchase.status'))->toBe('received')->and($r->json('purchase.variance'))->toBeFalse()
        ->and($r->json('purchase.receivedOn'))->toBe('2026-11-10');
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/receive", ['receivedKg' => 25500])->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/change", [
        'supplier' => 'x', 'material' => 'y', 'qtyKg' => 1, 'unitPrice' => 1, 'currency' => 'UGX', 'status' => 'ordered',
    ])->assertStatus(409);

    $q = order($this, $this->store)->json('purchase');
    $short = $this->withToken($this->store)->postJson("/api/v1/sites/mats/purchases/{$q['id']}/receive", ['receivedKg' => 25000])->assertCreated();
    $expected = Arithmetic::landedCost(['qtyKg' => 25500, 'unitPrice' => 1.1, 'currency' => 'USD', 'fx' => 3700, 'costs' => $f['input'][0]['costs'], 'receivedKg' => 25000]);
    expect($short->json('purchase.variance'))->toBeTrue()->and($short->json('purchase.landed.perKgUgx'))->toEqual($expected['perKgUgx']);
    expect(DB::table('cash_entries')->count())->toBe(0);
});

it('validates input and refuses a future receipt day or a missing fx', function (): void {
    order($this, $this->store, ['qtyKg' => 0])->assertStatus(422);
    order($this, $this->store, ['qtyKg' => 1e8])->assertStatus(422);
    order($this, $this->store, ['currency' => 'EUR'])->assertStatus(422);
    order($this, $this->store, ['costs' => [['label' => '', 'amount' => -1, 'currency' => 'UGX']]])->assertStatus(422);
    $id = (string) Str::uuid7();
    order($this, $this->store, ['id' => $id])->assertCreated();
    order($this, $this->store, ['id' => $id])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/purchases/{$id}/receive", ['receivedKg' => 10, 'receivedOn' => '2026-11-11'])->assertStatus(422);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/purchases/{$id}/receive", ['receivedKg' => 0])->assertStatus(422);
    DB::table('fx_rates')->delete();
    order($this, $this->store)->assertStatus(409);
    order($this, $this->store, ['currency' => 'UGX', 'unitPrice' => 4000, 'costs' => []])->assertCreated();
});

it('enforces site and role access on writes, reads and the snapshot', function (): void {
    order($this, $this->supervisor)->assertForbidden();
    order($this, $this->bagsManager)->assertForbidden();
    order($this, $this->cashier)->assertForbidden();
    order($this, $this->ceo)->assertCreated();
    $p = order($this, $this->store)->assertCreated()->json('purchase');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/receive", ['receivedKg' => 10])->assertForbidden();
    $this->withToken($this->bagsManager)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/receive", ['receivedKg' => 10])->assertForbidden();

    foreach ([$this->ceo, $this->admin, $this->manager, $this->store, $this->cashier] as $t) {
        expect($this->withToken($t)->getJson('/api/v1/sites/mats/purchases')->assertOk()->json('purchases'))->toHaveCount(2);
    }
    $this->withToken($this->supervisor)->getJson('/api/v1/sites/mats/purchases')->assertForbidden();
    $this->withToken($this->bagsManager)->getJson('/api/v1/sites/mats/purchases')->assertForbidden();

    $snap = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/snapshots/purchases?since=0')->assertOk();
    expect($snap->json('data'))->toHaveCount(2);
    $this->withToken($this->supervisor)->getJson('/api/v1/sites/mats/snapshots/purchases?since=0')->assertForbidden();
    $this->withToken($this->bagsManager)->getJson('/api/v1/sites/mats/snapshots/purchases?since=0')->assertForbidden();
});

it('rebuilds exactly from the ledger', function (): void {
    $a = order($this, $this->store)->json('purchase');
    order($this, $this->store, ['material' => 'LDPE'])->assertCreated();
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/purchases/{$a['id']}/change", [
        'supplier' => 'S2', 'material' => 'HDPE resin', 'qtyKg' => 20000, 'unitPrice' => 1, 'currency' => 'USD', 'costs' => [], 'status' => 'at_border',
    ])->assertCreated();
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/purchases/{$a['id']}/receive", ['receivedKg' => 19000])->assertCreated();

    $before = purchaseRows();
    app(Replay::class)->rebuild();
    expect(purchaseRows())->toEqual($before);
    app(Replay::class)->rebuild();
    expect(purchaseRows())->toEqual($before);
    expect((int) DB::table('number_series')->where('kind', 'purchase_order')->value('next'))->toBe(3);
});

it('moves status only forward and refuses a future order day', function (): void {
    $p = order($this, $this->store)->json('purchase');
    $change = fn (string $status) => $this->withToken($this->manager)->postJson("/api/v1/sites/mats/purchases/{$p['id']}/change", [
        'supplier' => 'Polymers Ltd', 'material' => 'HDPE resin', 'qtyKg' => 25500, 'unitPrice' => 1.1, 'currency' => 'USD', 'costs' => [], 'status' => $status,
    ]);
    $change('at_border')->assertCreated();
    $change('at_border')->assertCreated();
    $change('in_transit')->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
    $change('ordered')->assertStatus(422);
    expect(DB::table('purchases')->where('id', $p['id'])->value('status'))->toBe('at_border');

    // occurred_at tomorrow (within the envelope's 24h skew) would put ordered_on in the future.
    $r = $this->withToken($this->store)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [[
        'id' => (string) Str::uuid7(), 'type' => 'purchases.recorded', 'occurred_at' => CarbonImmutable::parse('2026-11-11 08:00:00', 'Africa/Kampala')->toIso8601String(),
        'payload' => ['supplier' => 'Polymers Ltd', 'material' => 'HDPE resin', 'qty_kg' => 100, 'unit_price' => 4000, 'currency' => 'UGX', 'costs' => []],
    ]]]);
    expect($r->getContent())->toContain('ordered_on')
        ->and(DB::table('purchases')->count())->toBe(1);
});
