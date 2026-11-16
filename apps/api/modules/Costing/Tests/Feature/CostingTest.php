<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Costing\Database\Seeders\CostingSeeder;
use Tanaad\Costing\Projectors\CostingProjector;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\Production\Database\Seeders\ProductionSeeder;
use Tanaad\Purchases\Database\Seeders\PurchasesSeeder;
use Tanaad\Recycling\Database\Seeders\RecyclingSeeder;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->manager = $token('u_sm_mats');
    $this->cashier = $token('u_cash_mats');
    $this->ceo = $token('u_ceo1');
    $this->otherManager = $token('u_sm_bags');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->product = DB::table('products')->join('prices', 'prices.product_id', '=', 'products.id')
        ->where(['products.site_id' => $this->mats, 'products.active' => true, 'prices.channel' => 'local', 'prices.currency' => 'UGX'])
        ->whereNotIn('products.kind', ['roll', 'material', 'pellet'])->orderBy('products.internal_name')->select('products.id', 'prices.amount')->first();
});

/** Appends another module's fact to the ledger and projects it into Costing only (the producing module is not under test). */
function costFact(string $type, string $siteId, array $payload, string $at = '2026-10-05T10:00:00+03:00'): void
{
    $stored = app(EventStore::class)->append([new Event(id: (string) Str::uuid7(), type: $type, siteId: $siteId, actorId: null, deviceId: null,
        occurredAt: CarbonImmutable::parse($at), payload: $payload)]);
    app(CostingProjector::class)->apply($stored[0]);
}

function costBooks(Tests\TestCase $t): void
{
    $cash = fn (array $b) => $t->withToken($t->cashier)->postJson('/api/v1/sites/mats/cash/entries', $b)->assertCreated();
    $cash(['day' => '2026-10-05', 'direction' => 'in', 'category' => 'sale', 'amount' => 1_000_000]);
    $cash(['day' => '2026-10-05', 'direction' => 'in', 'category' => 'receipt', 'amount' => 777]);
    $cash(['day' => '2026-10-05', 'direction' => 'out', 'category' => 'utilities', 'amount' => 200_000]);
    $cash(['day' => '2026-10-05', 'direction' => 'out', 'category' => 'materials', 'amount' => 300_000]);
    $t->withToken($t->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-10-05/close', ['counted' => 500_777])->assertCreated();
    $cash(['day' => '2026-10-06', 'direction' => 'out', 'category' => 'utilities', 'amount' => 99_999]); // open day: not counted

    $mats = $t->mats;
    costFact('production.entry_recorded', $mats, ['entry_id' => (string) Str::uuid7(), 'day' => '2026-10-05', 'stage_code' => 'weave', 'product_id' => $t->product->id, 'good_units' => 500, 'output_kg' => 1000]);
    costFact('production.entry_recorded', $mats, ['entry_id' => (string) Str::uuid7(), 'day' => '2026-10-05', 'stage_code' => 'mixer', 'product_id' => null, 'good_units' => 0, 'output_kg' => 900]);
    costFact('purchases.received', $mats, ['purchase_id' => (string) Str::uuid7(), 'received_kg' => 1000, 'received_on' => '2026-10-01', 'total_ugx' => 2_000_000]);
    costFact('people.payout_recorded', $mats, ['payout_id' => (string) Str::uuid7(), 'amount' => 100_000, 'method' => 'mobile_money', 'paid_at' => '2026-10-05T17:00:00+03:00']);
    costFact('spares.part_issued', $mats, ['issue_id' => (string) Str::uuid7(), 'machine_id' => (string) Str::uuid7(), 'cost' => 50_000]);
    costFact('orders.invoiced', $mats, ['invoice_id' => (string) Str::uuid7(), 'order_id' => (string) Str::uuid7(), 'number' => 'INV-1', 'total' => 400_000, 'currency' => 'UGX', 'prepaid' => 0, 'fully_paid' => false, 'issued_day' => '2026-10-05']);
    costFact('orders.invoiced', $mats, ['invoice_id' => (string) Str::uuid7(), 'currency' => 'UGX', 'issued_day' => '2026-10-05']); // no total: ignored
}

function costingRows(): array
{
    $out = [];
    foreach (CostingProjector::TABLES as $table) {
        $out[$table] = DB::table($table)->get()->map(function ($r): array {
            $a = (array) $r;
            unset($a['created_at'], $a['updated_at']);

            return $a;
        })->sortBy(fn (array $a): string => json_encode($a))->values()->all();
    }

    return $out;
}

it('computes a provisional P&L and unit cost from cash-closed days only', function (): void {
    costBooks($this);
    $r = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/costing/pnl?month=2026-10')->assertOk();
    expect($r->json('provisional'))->toBeTrue()
        ->and($r->json('closed_days'))->toBe(['2026-10-05'])
        ->and($r->json('open_days'))->toBe(30)
        ->and($r->json('revenue'))->toEqual(['cash_sales' => 1_000_000, 'invoiced' => 400_000, 'total' => 1_400_000, 'unconverted_lines' => 0])
        ->and($r->json('costs.by_category'))->toEqual(['materials' => 300_000, 'utilities' => 200_000, 'wages' => 100_000])
        ->and($r->json('costs.cash'))->toEqual(500_000)->and($r->json('costs.non_cash'))->toEqual(100_000)
        ->and($r->json('costs.excluded'))->toEqual(50_000)->and($r->json('costs.total'))->toEqual(600_000)
        ->and($r->json('net'))->toEqual(800_000)->and($r->json('margin_pct'))->toEqual(57.1)
        ->and($r->json('unit_costs'))->toBeNull();

    $u = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/costing/unit-cost?from=2026-10-01&to=2026-10-31')->assertOk();
    $price = (float) $this->product->amount;
    // material 1000 kg × 2000/kg ÷ 500 units = 4000; overhead (600000 − 300000) × 1000/1000 ÷ 500 = 600
    expect($u->json('material'))->toEqual(['per_kg' => 2000, 'source' => 'purchases'])
        ->and($u->json('unit_costs.0'))->toMatchArray(['product_id' => $this->product->id, 'basis' => 'unit', 'units' => 500, 'material' => 4000, 'overhead' => 600, 'total' => 4600,
            'margin_pct' => (int) floor(($price - 4600) / $price * 100 + 0.5)]);
});

it('freezes a closed period, refuses a second close, and reopens with a reason', function (): void {
    costBooks($this);
    $c = $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/periods/close', ['month' => '2026-10'])->assertCreated();
    expect($c->json('period.provisional'))->toBeFalse()->and($c->json('period.version'))->toBe(1)->and($c->json('period.net'))->toEqual(800_000);

    // A late invoice on a closed day does not move the frozen figures.
    costFact('orders.invoiced', $this->mats, ['invoice_id' => (string) Str::uuid7(), 'total' => 1000, 'currency' => 'UGX', 'prepaid' => 0, 'issued_day' => '2026-10-05']);
    expect($this->withToken($this->manager)->getJson('/api/v1/sites/mats/costing/pnl?month=2026-10')->json('revenue.total'))->toEqual(1_400_000);

    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/periods/close', ['month' => '2026-10'])->assertStatus(409);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/periods/reopen', ['month' => '2026-10'])->assertStatus(422);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/periods/reopen', ['month' => '2026-10', 'reason' => 'late invoice'])->assertCreated()
        ->assertJsonPath('period.provisional', true)->assertJsonPath('period.status', 'reopened');
    $again = $this->withToken($this->ceo)->postJson('/api/v1/sites/mats/costing/periods/close', ['month' => '2026-10'])->assertCreated();
    expect($again->json('period.version'))->toBe(2)->and($again->json('period.revenue.total'))->toEqual(1_401_000)
        ->and(DB::table('events')->where('type', 'costing.period_closed')->count())->toBe(2);
});

it('drops a reopened cash day and applies the site cost mapping', function (): void {
    costBooks($this);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/mapping', ['sourceKey' => 'spares_issue', 'costCategory' => 'spares'])->assertCreated();
    expect($this->withToken($this->manager)->getJson('/api/v1/sites/mats/costing/pnl?month=2026-10')->json('costs.by_category.spares'))->toEqual(50_000);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/mapping', ['sourceKey' => 'spares_issue', 'costCategory' => 'nonsense'])->assertStatus(422);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/mapping', ['sourceKey' => 'bogus', 'costCategory' => 'spares'])->assertStatus(422);

    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/cash/days/2026-10-05/reopen', ['reason' => 'recount'])->assertCreated();
    $r = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/costing/pnl?month=2026-10')->assertOk();
    expect($r->json('closed_days'))->toBe([])->and($r->json('revenue.total'))->toEqual(0)->and($r->json('costs.total'))->toEqual(0);
});

it('scopes reads and writes by role and site', function (): void {
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/costing/pnl?month=2026-10')->assertForbidden();
    $this->withToken($this->otherManager)->getJson('/api/v1/sites/mats/costing/pnl?month=2026-10')->assertForbidden();
    $this->withToken($this->otherManager)->postJson('/api/v1/sites/mats/costing/periods/close', ['month' => '2026-10'])->assertForbidden();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/costing/periods/close', ['month' => '2026-10'])->assertForbidden();
    $this->withToken($this->manager)->getJson('/api/v1/costing/pnl?month=2026-10')->assertForbidden();
    $g = $this->withToken($this->ceo)->getJson('/api/v1/costing/pnl?month=2026-10')->assertOk();
    expect($g->json('sites'))->toHaveCount(DB::table('sites')->count())->and($g->json('group.fx_usd_ugx'))->not->toBeNull();
});

it('validates periods', function (): void {
    foreach (['month=2026-13', 'from=2026-10-05', 'from=2026-10-09&to=2026-10-01', 'from=2025-01-01&to=2026-10-01'] as $q) {
        $this->withToken($this->manager)->getJson("/api/v1/sites/mats/costing/pnl?{$q}")->assertStatus(422);
    }
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/periods/close', ['month' => '2099-01'])->assertStatus(422);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/periods/reopen', ['month' => '2026-09', 'reason' => 'x'])->assertNotFound();
});

it('closes the month from the scheduler once and rebuilds exactly from the ledger', function (): void {
    costBooks($this);
    $this->artisan('costing:close-period', ['--month' => '2026-10', '--site' => 'mats'])->expectsOutput('Closed 1.')->assertSuccessful();
    $this->artisan('costing:close-period', ['--month' => '2026-10', '--site' => 'mats'])->expectsOutput('Closed 0.')->assertSuccessful();
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/costing/mapping', ['sourceKey' => 'cash:utilities', 'costCategory' => 'other'])->assertCreated();

    $before = costingRows();
    app(Replay::class)->rebuild([CostingProjector::class]); // the hand-made facts of other modules are only Costing-complete
    expect(costingRows())->toEqual($before);
});

it('seeds a closed October through the ledger and rebuilds it exactly', function (): void {
    $seed = function (): void {
        foreach ([PeopleSeeder::class, CashSeeder::class, RecyclingSeeder::class, ProductionSeeder::class, PurchasesSeeder::class, CostingSeeder::class] as $s) {
            app()->call([new $s, 'run']);
        }
    };
    $seed();
    $events = DB::table('events')->count();
    $before = costingRows();
    $closedOctober = DB::table('costing_closed_days')->where('closed', true)->whereBetween('day', ['2026-10-01', '2026-10-31'])->distinct()->count('site_id');
    expect($closedOctober)->toBeGreaterThan(0)
        ->and(DB::table('costing_periods')->where(['label' => '2026-10', 'status' => 'closed'])->count())->toBe($closedOctober);

    $seed();
    expect(DB::table('events')->count())->toBe($events);
    app(Replay::class)->rebuild(); // every projector, over the mixed seeded ledger
    expect(costingRows())->toEqual($before);
});

it('counts a local order once: its confirmed cash on the cash day, invoiced or not; a credit invoice in full; counter cash sales still count', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00', 'Africa/Kampala'));
    $sales = User::query()->where('code', 'u_sales_mats')->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $client = (string) $this->withToken($sales)->postJson('/api/v1/sites/mats/clients', ['name' => 'Owino Traders', 'country' => 'Uganda', 'channel' => 'local', 'currencyPref' => 'UGX'])
        ->assertCreated()->json('client.id');
    $order = $this->withToken($sales)->postJson('/api/v1/sites/mats/orders', ['clientId' => $client, 'lines' => [['productId' => $this->product->id, 'bundles' => 1]]])->assertCreated();
    $total = (float) $order->json('order.total');
    $id = (string) $order->json('order.id');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/orders/{$id}/confirm-cash", ['amount' => $total])->assertOk();
    $this->withToken($sales)->postJson("/api/v1/sites/mats/orders/{$id}/invoice")->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/entries', ['day' => '2026-10-05', 'direction' => 'in', 'category' => 'sale', 'amount' => 50_000])->assertCreated();

    // A second local order, cash confirmed but never invoiced, still counts.
    $never = (string) $this->withToken($sales)->postJson('/api/v1/sites/mats/orders', ['clientId' => $client, 'lines' => [['productId' => $this->product->id, 'bundles' => 2]]])->assertCreated()->json('order.id');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/orders/{$never}/confirm-cash", ['amount' => 2 * $total])->assertOk();
    // A credit (export) order invoiced without cash counts its invoice in full.
    $ceo = User::query()->where('code', 'u_ceo1')->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $export = (string) $this->withToken($ceo)->postJson('/api/v1/sites/mats/clients', ['name' => 'Juba', 'country' => 'South Sudan', 'channel' => 'export', 'currencyPref' => 'UGX', 'creditLimit' => 99_000_000])->assertCreated()->json('client.id');
    $eo = $this->withToken($sales)->postJson('/api/v1/sites/mats/orders', ['clientId' => $export, 'lines' => [['productId' => $this->product->id, 'bundles' => 1]]])->assertCreated();
    $this->withToken($ceo)->postJson('/api/v1/sites/mats/orders/'.$eo->json('order.id').'/approve')->assertOk();
    $this->withToken($sales)->postJson('/api/v1/sites/mats/orders/'.$eo->json('order.id').'/invoice')->assertCreated();
    $exportTotal = (float) $eo->json('order.total');
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-10-05/close', ['counted' => 3 * $total + 50_000])->assertCreated();

    // The prepaid invoice adds nothing (its cash is already revenue); the credit invoice adds its total.
    expect(DB::table('costing_revenue_lines')->where('source', 'order_cash')->count())->toBe(2)
        ->and(DB::table('costing_revenue_lines')->where('source', 'invoice')->count())->toBe(1);
    $r = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/costing/pnl?month=2026-10')->assertOk();
    expect($r->json('revenue'))->toEqual(['cash_sales' => 3 * $total + 50_000, 'invoiced' => $exportTotal, 'total' => 3 * $total + 50_000 + $exportTotal, 'unconverted_lines' => 0]);

    $before = costingRows();
    app(Replay::class)->rebuild();
    expect(costingRows())->toEqual($before);
    CarbonImmutable::setTestNow();
});

it('dates payouts and part issues in the site timezone', function (): void {
    DB::table('sites')->where('id', $this->mats)->update(['timezone' => 'Africa/Kampala']);
    config(['tanaad.timezone' => 'UTC']);
    $payout = (string) Str::uuid7();
    $issue = (string) Str::uuid7();
    costFact('people.payout_recorded', $this->mats, ['payout_id' => $payout, 'amount' => 1000, 'method' => 'cash', 'paid_at' => '2026-10-05T22:30:00+00:00']);
    costFact('spares.part_issued', $this->mats, ['issue_id' => $issue, 'cost' => 1000], '2026-10-05T22:30:00+00:00');
    expect(DB::table('costing_cost_lines')->where('id', $payout)->value('day'))->toStartWith('2026-10-06')
        ->and(DB::table('costing_cost_lines')->where('id', $issue)->value('day'))->toStartWith('2026-10-06');
});
