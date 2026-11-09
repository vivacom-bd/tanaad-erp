<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Models\RefItem;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->admin = $token('u_admin');
    $this->bagsManager = $token('u_sm_bags');
    $this->matsManager = $token('u_sm_mats');
    $this->cashier = $token('u_cash_mats');
});

it('returns the whole site configuration in the prototype shape', function (): void {
    $r = $this->withToken($this->bagsManager)->getJson('/api/v1/sites/bags/config')->assertOk();
    expect($r->json('site.code'))->toBe('bags')
        ->and($r->json('site.machineWallTitle'))->toBe('Extruder Wall')
        ->and($r->json('site.stages'))->toHaveCount(4)
        ->and($r->json('settings.massGapPct'))->toEqual(2)
        ->and($r->json('settings.materialCostPerKg'))->toEqual(5200)
        ->and(count($r->json('refs')))->toBeGreaterThanOrEqual(20)
        ->and(collect($r->json('refs'))->firstWhere('code', 'wages')['system'])->toBeTrue()
        ->and($r->json('products.0.prices'))->toBeArray()
        ->and(collect($r->json('products'))->firstWhere('code', 'p_bag_small_black')['prices'][0]['amount'])->toEqual(9000)
        ->and($r->json('machines'))->toHaveCount(12)
        ->and($r->json('fx'))->toEqual(3700)
        ->and($r->json('companyProfile.tin'))->toBe('')
        ->and(collect($r->json('numberSeries'))->firstWhere('kind', 'delivery_note'))->toMatchArray(['prefix' => 'DN', 'next' => 4817])
        ->and($r->json('snapshot_seq'))->toBeGreaterThan(0);

    $mats = $this->withToken($this->matsManager)->getJson('/api/v1/sites/mats/config')->assertOk();
    expect($mats->json('benchmarks'))->toHaveCount(21 * 5);
});

it('forbids a cashier from changing site settings', function (): void {
    $settings = $this->withToken($this->matsManager)->getJson('/api/v1/sites/mats/config')->json('settings');
    $this->withToken($this->cashier)->putJson('/api/v1/sites/mats/settings', $settings)->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->withToken($this->matsManager)->putJson('/api/v1/sites/mats/settings', [...$settings, 'massGapPct' => 3])
        ->assertCreated()->assertJsonPath('settings.massGapPct', 3);
    $this->withToken($this->matsManager)->putJson('/api/v1/sites/bags/settings', $settings)->assertForbidden();
});

function addVehicle(Tests\TestCase $t, string $token): array
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/refs', ['list' => 'vehicle', 'label' => 'UAZ 100A', 'order' => 9])->assertCreated()->json();
}

it('lets a site manager add a ref item and records the event', function (): void {
    $seededSeq = (int) DB::table('events')->max('seq');
    $r = addVehicle($this, $this->matsManager);
    expect($r['ref']['label'])->toBe('UAZ 100A')->and($r['event_id'])->toBeString();
    $refs = $this->withToken($this->matsManager)->getJson('/api/v1/sites/mats/config')->json('refs');
    expect(collect($refs)->firstWhere('id', $r['ref']['id']))->not->toBeNull()
        ->and(DB::table('events')->where('type', 'config.ref_list_changed')->where('seq', '>', $seededSeq)->count())->toBe(1);

    $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/refs', ['list' => 'nonsense', 'label' => 'x'])->assertStatus(422);
    $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/refs', ['list' => 'market', 'label' => 'Kenya', 'siteId' => null])->assertForbidden();
});

it('refuses to deactivate a system ref item', function (): void {
    $wages = RefItem::query()->where('code', 'wages')->firstOrFail();
    $this->withToken($this->admin)->postJson('/api/v1/sites/mats/refs', [
        'id' => $wages->id, 'list' => 'expense_category', 'label' => 'Wages', 'siteId' => null, 'active' => false,
    ])->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    $this->withToken($this->admin)->postJson('/api/v1/sites/mats/refs', [
        'id' => $wages->id, 'list' => 'expense_category', 'label' => 'Salaries & wages', 'siteId' => null,
    ])->assertCreated()->assertJsonPath('ref.code', 'wages')->assertJsonPath('ref.system', true);
});

it('lets only a group admin set the FX rate', function (): void {
    $body = ['day' => now()->toDateString(), 'rate' => 3810.5];
    $this->withToken($this->matsManager)->postJson('/api/v1/fx', $body)->assertForbidden();
    $this->withToken($this->admin)->postJson('/api/v1/fx', $body)->assertCreated()->assertJsonPath('fx.rate', 3810.5);
    expect($this->withToken($this->matsManager)->getJson('/api/v1/sites/mats/config')->json('fx'))->toEqual(3810.5);
    $this->withToken($this->admin)->postJson('/api/v1/fx', [...$body, 'rate' => 0])->assertStatus(422);
});

it('saves products, machines and benchmarks through the ledger', function (): void {
    $p = $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/products', [
        'internalName' => 'Test mat', 'kind' => 'mat', 'bundlePieces' => 5, 'stdBundleWeightKg' => 10, 'weightTolerancePct' => 2,
        'weightStatus' => 'to_be_measured', 'prices' => [['channel' => 'local', 'currency' => 'UGX', 'amount' => 1000, 'effectiveFrom' => '2026-11-01']],
    ])->assertCreated()->assertJsonPath('product.prices.0.amount', 1000)->json('product');
    $m = $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/machines', ['code' => 'Loom 22', 'kind' => 'loom', 'stageCode' => 'weave', 'status' => 'arriving'])
        ->assertCreated()->json('machine');
    $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/machines', ['code' => 'X', 'kind' => 'loom', 'stageCode' => 'nope', 'status' => 'active'])
        ->assertNotFound();
    $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/benchmarks', ['machineId' => $m['id'], 'productId' => $p['id'], 'unitsPerShift' => 12])
        ->assertCreated()->assertJsonPath('benchmark.unitsPerShift', 12);
    $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/zones', ['name' => 'Bay 1'])->assertCreated()->assertJsonPath('zone.name', 'Bay 1');
    $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/number-series', ['kind' => 'gate_pass', 'prefix' => 'GP'])
        ->assertCreated()->assertJsonPath('numberSeries.next', 1);
    $this->withToken($this->matsManager)->postJson('/api/v1/sites/mats/company-profile', ['vatRatePct' => 18])->assertForbidden();
    $this->withToken($this->admin)->postJson('/api/v1/sites/mats/company-profile', ['vatRatePct' => 18, 'tin' => '100'])
        ->assertCreated()->assertJsonPath('companyProfile.vatRatePct', 18);
});

it('rebuilds every seeded and posted row from the ledger', function (): void {
    $added = addVehicle($this, $this->matsManager);
    $refs = RefItem::query()->count();
    $products = Product::query()->count();
    app(Replay::class)->rebuild();
    expect(RefItem::query()->count())->toBe($refs)
        ->and(Product::query()->count())->toBe($products)
        ->and(RefItem::query()->find($added['ref']['id']))->not->toBeNull();
});

it('returns seeded rows in the snapshot delta since 0', function (): void {
    $mats = (string) Site::query()->where('code', 'mats')->value('id');
    $rows = [];
    $since = 0;
    do {
        $r = $this->withToken($this->matsManager)->getJson("/api/v1/sites/mats/snapshots/ref_items?since={$since}")->assertOk();
        $rows = [...$rows, ...$r->json('data')];
        $since = (int) $r->json('snapshot_seq');
    } while ($r->json('has_more') === true);
    $expected = RefItem::query()->where(fn ($q) => $q->where('site_id', $mats)->orWhereNull('site_id'))->count();
    expect($rows)->toHaveCount($expected)
        ->and(collect($rows)->every(fn (array $x): bool => $x['seq'] > 0))->toBeTrue();

    $products = $this->withToken($this->matsManager)->getJson('/api/v1/sites/mats/snapshots/products?since=0&limit=500')->assertOk()->json('data');
    expect(collect($products)->pluck('code')->sort()->values()->all())
        ->toBe(Product::query()->where('site_id', $mats)->orderBy('code')->pluck('code')->all());
});
