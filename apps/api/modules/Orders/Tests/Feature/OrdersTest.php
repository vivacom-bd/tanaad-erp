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
use Tanaad\Orders\Projectors\OrdersProjector;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00:00', 'Africa/Kampala'));
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->sales = $token('u_sales_mats');
    $this->salesBags = $token('u_sales_bags');
    $this->cashier = $token('u_cash_mats');
    $this->store = $token('u_store_mats');
    $this->manager = $token('u_sm_mats');
    $this->ceo = $token('u_ceo1');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->product = fn (string $code): string => (string) DB::table('products')->where('code', $code)->value('id');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function salesClient(Tests\TestCase $t, string $token, array $body = []): string
{
    return (string) $t->withToken($token)->postJson('/api/v1/sites/mats/clients', [
        'name' => 'Owino Market Traders', 'country' => 'Uganda', 'channel' => 'local', 'currencyPref' => 'UGX', 'contact' => 'Mama Nalongo', ...$body,
    ])->assertCreated()->json('client.id');
}

/** @param list<array<string, mixed>> $lines */
function salesOrder(Tests\TestCase $t, string $token, string $client, array $lines): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/orders', ['clientId' => $client, 'lines' => $lines]);
}

/** @return array<string, mixed> */
function ordersRows(): array
{
    $out = [];
    foreach (['clients', 'orders', 'invoices', 'payments', 'cash_entries', 'exceptions'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($r): array {
            $a = (array) $r;
            unset($a['created_at'], $a['updated_at']);

            return $a;
        })->all();
    }

    return $out;
}

it('keeps clients as group-wide master data, validated against the market list', function (): void {
    $id = salesClient($this, $this->sales);
    $this->withToken($this->salesBags)->getJson('/api/v1/sites/bags/clients')->assertOk()->assertJsonPath('clients.0.id', $id);
    $this->withToken($this->sales)->postJson('/api/v1/sites/mats/clients', ['name' => 'X', 'country' => 'Atlantis', 'channel' => 'local', 'currencyPref' => 'UGX'])
        ->assertStatus(422)->assertJsonPath('details.errors.country', 'must be a market from master data');
    $this->withToken($this->sales)->postJson('/api/v1/sites/mats/clients', ['name' => 'X', 'country' => 'Uganda', 'channel' => 'retail', 'currencyPref' => 'ugx', 'creditDays' => -1])
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['channel', 'currency_pref', 'credit_days']]]);
    $this->withToken($this->ceo)->putJson("/api/v1/sites/mats/clients/{$id}", ['name' => 'Owino Traders', 'country' => 'Uganda', 'channel' => 'export', 'currencyPref' => 'USD', 'creditLimit' => 5000, 'creditDays' => 30])
        ->assertOk()->assertJsonPath('client.creditLimit', 5000)->assertJsonPath('client.channel', 'export');
    $this->withToken($this->sales)->putJson('/api/v1/sites/mats/clients/'.Str::uuid7(), ['name' => 'Y', 'country' => 'Uganda', 'channel' => 'local', 'currencyPref' => 'UGX'])->assertNotFound();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/clients', ['name' => 'Z', 'country' => 'Uganda', 'channel' => 'local', 'currencyPref' => 'UGX'])->assertForbidden();
    $this->withToken($this->store)->getJson('/api/v1/sites/mats/clients')->assertForbidden();
});

it('lets only the CEO or group admin set or change credit terms; sales edit contact details only', function (): void {
    $this->withToken($this->sales)->postJson('/api/v1/sites/mats/clients', ['name' => 'X', 'country' => 'Uganda', 'channel' => 'export', 'currencyPref' => 'USD', 'creditLimit' => 5000])
        ->assertForbidden()->assertJsonPath('detail', "Only the CEO or group admin may set or change a client's credit limit, credit days, channel or currency.");
    $id = salesClient($this, $this->ceo, ['channel' => 'export', 'currencyPref' => 'USD', 'creditLimit' => 5000, 'creditDays' => 30]);
    foreach ([['creditLimit' => 9000], ['creditDays' => 60], ['channel' => 'local'], ['currencyPref' => 'UGX']] as $change) {
        $this->withToken($this->manager)->putJson("/api/v1/sites/mats/clients/{$id}", ['name' => 'Owino', 'country' => 'Uganda', ...$change])->assertForbidden();
    }
    // Unchanged terms (sent or omitted) are fine; omitted ones keep their value.
    $this->withToken($this->sales)->putJson("/api/v1/sites/mats/clients/{$id}", ['name' => 'Owino', 'country' => 'Uganda', 'contact' => 'New', 'creditLimit' => 5000, 'channel' => 'export'])->assertOk();
    $this->withToken($this->sales)->putJson("/api/v1/sites/mats/clients/{$id}", ['name' => 'Owino 2', 'country' => 'Uganda'])->assertOk()
        ->assertJsonPath('client.creditLimit', 5000)->assertJsonPath('client.creditDays', 30)->assertJsonPath('client.channel', 'export')->assertJsonPath('client.contact', '');
    $this->withToken($this->ceo)->putJson("/api/v1/sites/mats/clients/{$id}", ['name' => 'Owino', 'country' => 'Uganda', 'creditLimit' => 9000])->assertOk()->assertJsonPath('client.creditLimit', 9000);
});

it('counts committed uninvoiced orders, in the client currency only, against the credit limit', function (): void {
    $client = salesClient($this, $this->ceo, ['name' => 'Juba', 'country' => 'South Sudan', 'channel' => 'export', 'currencyPref' => 'USD', 'creditLimit' => 1000]);
    // Sequential: 700 fits, a further 700 does not (1,400 committed > 1,000).
    $a = salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 10]])->assertCreated();
    expect($a->json('order.overLimit'))->toBeFalse();
    $this->withToken($this->ceo)->postJson('/api/v1/sites/mats/orders/'.$a->json('order.id').'/approve')->assertOk();
    expect(salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 10]])->json('order.overLimit'))->toBeTrue();

    // Concurrent-style (both awaiting approval, neither invoiced) on a fresh client: the second sees the first.
    $other = salesClient($this, $this->ceo, ['name' => 'Kigali', 'country' => 'Rwanda', 'channel' => 'export', 'currencyPref' => 'USD', 'creditLimit' => 1000]);
    expect(salesOrder($this, $this->sales, $other, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 8]])->json('order.overLimit'))->toBeFalse()
        ->and(salesOrder($this, $this->sales, $other, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 8]])->json('order.overLimit'))->toBeTrue();

    // Another currency's exposure is not added to a USD limit.
    $usd = (string) $other;
    DB::table('orders')->where('client_id', $usd)->update(['currency' => 'UGX']);
    expect(salesOrder($this, $this->sales, $usd, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 8]])->json('order.overLimit'))->toBeFalse();
});

it('prices a local order server-side, numbers it, and books confirmed cash as a sales cash-in once', function (): void {
    $client = salesClient($this, $this->sales);
    $r = salesOrder($this, $this->sales, $client, [
        ['productId' => ($this->product)('p_rihlaat'), 'bundles' => 2, 'unitPrice' => 1],
        ['productId' => ($this->product)('p_sajaada_h'), 'bundles' => 1],
    ])->assertCreated();
    // Rihlaat: UGX 15,000 × 20 pieces; Sajaada heavy: USD 7.5 × 10 pieces at 3,700.
    expect($r->json('order.number'))->toBe('SO-00001')
        ->and($r->json('order.status'))->toBe('awaiting_cash')
        ->and($r->json('order.fxReference'))->toEqual(3700)
        ->and($r->json('order.lines.0.unitPrice'))->toEqual(300000)
        ->and($r->json('order.lines.1.unitPrice'))->toEqual(277500)
        ->and($r->json('order.total'))->toEqual(877500);
    $order = $r->json('order.id');

    $this->withToken($this->sales)->postJson("/api/v1/sites/mats/orders/{$order}/confirm-cash", ['amount' => 877500])->assertForbidden();
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/orders/{$order}/confirm-cash", ['amount' => 800000])->assertStatus(422);
    $this->withToken($this->cashier)->getJson("/api/v1/sites/mats/orders/{$order}/dispatch-snapshot")->assertStatus(409);
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/orders/{$order}/confirm-cash", ['amount' => 877500])->assertOk()->assertJsonPath('order.status', 'approved');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/orders/{$order}/confirm-cash", ['amount' => 877500])->assertStatus(409);
    $cash = DB::table('cash_entries')->where('category', 'sale')->get();
    expect($cash)->toHaveCount(1)->and($cash[0]->direction)->toBe('in')->and((float) $cash[0]->amount)->toBe(877500.0)->and($cash[0]->payee)->toBe('Owino Market Traders');

    // Invoiced once, prepaid by the confirmed cash: nothing is owed, and a further payment would overpay.
    $inv = $this->withToken($this->sales)->postJson("/api/v1/sites/mats/orders/{$order}/invoice", ['efrisFdn' => null])->assertCreated();
    expect($inv->json('invoice.number'))->toBe('INV-00001')->and($inv->json('invoice.balance'))->toEqual(0)->and($inv->json('invoice.efrisFdn'))->toBeNull();
    $this->withToken($this->sales)->postJson("/api/v1/sites/mats/orders/{$order}/invoice")->assertStatus(409);
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/invoices/'.$inv->json('invoice.id').'/payments', ['amount' => 1, 'currency' => 'UGX', 'method' => 'Cash'])
        ->assertStatus(409)->assertJsonPath('details.rule', 'overpay');
});

it('refuses cash on a closed cash day', function (): void {
    $order = salesOrder($this, $this->sales, salesClient($this, $this->sales), [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 1]])->json('order.id');
    app(DayLocks::class)->lock($this->mats, 'cash', '2026-11-10', (string) Str::uuid7());
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/orders/{$order}/confirm-cash", ['amount' => 300000])->assertStatus(409)->assertJsonPath('code', 'day_locked');
});

it('validates order lines and site-scopes products', function (): void {
    $client = salesClient($this, $this->sales);
    salesOrder($this, $this->sales, $client, [])->assertStatus(422);
    salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_bag_small_black'), 'bundles' => 1]])->assertStatus(422)
        ->assertJsonPath('details.errors', ['lines.0.product_id' => 'is not an active product of this site']);
    salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 0]])->assertStatus(422);
    salesOrder($this, $this->sales, (string) Str::uuid7(), [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 1]])->assertStatus(422);
    $id = (string) Str::uuid7();
    $this->withToken($this->sales)->postJson('/api/v1/sites/mats/orders', ['orderId' => $id, 'clientId' => $client, 'lines' => [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 1]]])->assertCreated();
    $this->withToken($this->sales)->postJson('/api/v1/sites/mats/orders', ['orderId' => $id, 'clientId' => $client, 'lines' => [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 1]]])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    $this->withToken($this->salesBags)->postJson('/api/v1/sites/mats/orders', ['clientId' => $client, 'lines' => []])->assertForbidden();
    $this->withToken($this->salesBags)->getJson('/api/v1/sites/mats/orders')->assertForbidden();
    $this->withToken($this->store)->getJson('/api/v1/sites/mats/orders?status=awaiting_cash')->assertOk()->assertJsonCount(1, 'orders');
    $this->withToken($this->store)->getJson('/api/v1/sites/mats/invoices')->assertForbidden();
});

it('sends an over-limit export order to the CEO and settles the exception with the decision', function (): void {
    $client = salesClient($this, $this->ceo, ['name' => 'Juba Konyo-Konyo Traders', 'country' => 'South Sudan', 'channel' => 'export', 'currencyPref' => 'USD', 'creditLimit' => 1000, 'creditDays' => 45]);
    $small = salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 10]])->assertCreated();
    expect($small->json('order.status'))->toBe('awaiting_approval')->and($small->json('order.overLimit'))->toBeFalse()->and($small->json('order.total'))->toEqual(700);
    $big = salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 20]])->assertCreated();
    expect($big->json('order.overLimit'))->toBeTrue()->and($big->json('order.currency'))->toBe('USD');
    $order = $big->json('order.id');
    $ex = DB::table('exceptions')->where(['kind' => 'credit_over_limit', 'subject_ref' => $order])->first();
    expect($ex->owner_role)->toBe('ceo')->and($ex->status)->toBe('open')->and($ex->detail)->toContain('210%');

    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/orders/{$order}/approve")->assertForbidden();
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/orders/{$order}/hold")->assertStatus(422);
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/orders/{$order}/hold", ['reason' => 'Clear the July invoice first'])->assertOk()->assertJsonPath('order.status', 'held');
    expect(DB::table('exceptions')->where('id', $ex->id)->value('status'))->toBe('held');
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/orders/{$order}/approve", ['reason' => 'Paid by phone'])->assertOk()->assertJsonPath('order.status', 'approved');
    expect(DB::table('exceptions')->where('id', $ex->id)->first())->status->toBe('approved');
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/orders/{$order}/approve")->assertStatus(409);

    $snap = $this->withToken($this->store)->getJson("/api/v1/sites/mats/orders/{$order}/dispatch-snapshot")->assertOk();
    expect($snap->json())->toBe(['order_ref' => 'SO-00002', 'client_name' => 'Juba Konyo-Konyo Traders', 'site' => 'mats', 'lines' => [['product_id' => ($this->product)('p_rihlaat'), 'bundles' => 20]]]);
});

it('records payments against the invoice balance; cash ones reach the cash book; receivables and statement follow', function (): void {
    $client = salesClient($this, $this->ceo, ['name' => 'Kigali Home & Mat Traders', 'country' => 'Rwanda', 'channel' => 'export', 'currencyPref' => 'USD', 'creditLimit' => 40000, 'creditDays' => 30]);
    $order = salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 10]])->json('order.id');
    $this->withToken($this->sales)->postJson("/api/v1/sites/mats/orders/{$order}/invoice")->assertStatus(409);
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/orders/{$order}/approve")->assertOk();
    $inv = $this->withToken($this->sales)->postJson("/api/v1/sites/mats/orders/{$order}/invoice")->assertCreated()->json('invoice.id');
    $pay = fn (array $b, ?string $token = null) => $this->withToken($token ?? $this->cashier)->postJson("/api/v1/sites/mats/invoices/{$inv}/payments", ['currency' => 'USD', 'method' => 'Bank transfer', ...$b]);

    $pay(['amount' => 0])->assertStatus(422);
    $pay(['amount' => 10, 'currency' => 'UGX'])->assertStatus(422);
    $pay(['amount' => 10, 'method' => 'Barter'])->assertStatus(422);
    $pay(['amount' => 701])->assertStatus(409)->assertJsonPath('details.rule', 'overpay');
    $id = (string) Str::uuid7();
    $pay(['amount' => 500, 'paymentId' => $id, 'reference' => 'TT123456'])->assertCreated()->assertJsonPath('invoice.balance', 200);
    $pay(['amount' => 100, 'paymentId' => $id])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    expect(DB::table('cash_entries')->count())->toBe(0);

    $rec = $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/receivables')->assertOk();
    expect($rec->json('receivables.0.balance'))->toEqual(200)->and($rec->json('receivables.0.client.name'))->toBe('Kigali Home & Mat Traders');
    $pay(['amount' => 200, 'method' => 'Cash'])->assertCreated()->assertJsonPath('invoice.balance', 0);
    expect(DB::table('cash_entries')->where(['category' => 'sale', 'direction' => 'in', 'currency' => 'USD'])->sum('amount'))->toEqual(200);
    $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/receivables')->assertOk()->assertJsonCount(0, 'receivables');

    $st = $this->withToken($this->sales)->getJson("/api/v1/sites/mats/clients/{$client}/statement")->assertOk();
    expect($st->json('invoiced'))->toEqual(700)->and($st->json('paid'))->toEqual(700)->and($st->json('balance'))->toEqual(0)
        ->and($st->json('payments'))->toHaveCount(2)->and($st->json('invoices.0.dueDay'))->toBe('2026-12-10');
    $this->withToken($this->store)->getJson("/api/v1/sites/mats/clients/{$client}/statement")->assertForbidden();
    $this->withToken($this->salesBags)->getJson("/api/v1/sites/mats/clients/{$client}/statement")->assertForbidden();

    $before = ordersRows();
    app(Replay::class)->rebuild();
    expect(ordersRows())->toEqual($before);
});

it('follows dispatch by order number: picking, dispatched, delivered, then invoiced and paid', function (): void {
    $client = salesClient($this, $this->sales);
    $order = salesOrder($this, $this->sales, $client, [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 1]])->json('order.id');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/orders/{$order}/confirm-cash", ['amount' => 300000])->assertOk();
    $snap = $this->withToken($this->store)->getJson("/api/v1/sites/mats/orders/{$order}/dispatch-snapshot")->assertOk()->json();
    $this->withToken($this->store)->postJson('/api/v1/sites/mats/dispatches', ['driver' => 'Moses Kato', 'vehicle' => 'UBA 214K', 'order' => $snap])->assertCreated();
    $status = fn (): string => (string) DB::table('orders')->where('id', $order)->value('status');
    expect($status())->toBe('picking');

    $store = app(EventStore::class);
    $ctx = new CommandContext($this->mats, null, null, CarbonImmutable::now());
    $apply = function (string $type) use ($store, $ctx): void {
        foreach ($store->append([Event::from($ctx, $type, ['dispatch_id' => (string) Str::uuid7(), 'order_ref' => 'SO-00001'], primary: false)]) as $e) {
            app(OrdersProjector::class)->apply($e);
        }
    };
    $apply('dispatch.gate_passed');
    expect($status())->toBe('dispatched');
    $apply('dispatch.delivered');
    expect($status())->toBe('delivered');
    $this->withToken($this->sales)->postJson("/api/v1/sites/mats/orders/{$order}/invoice")->assertCreated()->assertJsonPath('order.status', 'paid');

    // The hand-made dispatch facts above are not full Dispatch events, so only this module's rows are rebuilt here.
    $before = ordersRows();
    app(Replay::class)->rebuild([OrdersProjector::class]);
    expect(ordersRows())->toEqual($before);
});

it('takes orders and payments offline through the event envelope, numbering them at sync', function (): void {
    $client = (string) Str::uuid7();
    $order = (string) Str::uuid7();
    $events = [
        ['id' => (string) Str::uuid7(), 'type' => 'orders.client_saved', 'occurred_at' => '2026-11-10T08:00:00+03:00',
            'payload' => ['client_id' => $client, 'name' => 'Jinja Road Wholesalers', 'country' => 'Uganda', 'channel' => 'local', 'currency_pref' => 'UGX']],
        ['id' => (string) Str::uuid7(), 'type' => 'orders.created', 'occurred_at' => '2026-11-10T08:05:00+03:00',
            'payload' => ['order_id' => $order, 'client_id' => $client, 'number' => 'TMP-1', 'lines' => [['product_id' => ($this->product)('p_rihlaat'), 'bundles' => 3, 'unit_price' => 1]]]],
        ['id' => (string) Str::uuid7(), 'type' => 'orders.client_saved', 'occurred_at' => '2026-11-10T08:06:00+03:00',
            'payload' => ['client_id' => $client, 'name' => 'Jinja Road Wholesalers Ltd', 'country' => 'Uganda', 'channel' => 'local', 'currency_pref' => 'UGX']],
    ];
    $r = $this->withToken($this->sales)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => $events])->assertOk();
    expect(array_column($r->json('results'), 'status'))->toBe(['accepted', 'accepted', 'accepted']);
    $row = DB::table('orders')->where('id', $order)->first();
    expect($row->number)->toBe('SO-00001')->and((float) $row->total)->toBe(900000.0)
        ->and(DB::table('clients')->where('id', $client)->value('name'))->toBe('Jinja Road Wholesalers Ltd');
});

it('serialises a seeded order and a live order created at the same instant identically, as UTC with Z', function (): void {
    $client = salesClient($this, $this->sales);
    $lines = [['productId' => ($this->product)('p_rihlaat'), 'bundles' => 1]];
    $live = (string) salesOrder($this, $this->sales, $client, $lines)->assertCreated()->json('order.id');
    // The seeders' path: a Kampala-offset occurred_at through the command bus (OrdersSeeder::ctx).
    $seededId = (string) Str::uuid7();
    $actor = (string) User::query()->where('code', 'u_sales_mats')->value('id');
    app(Tanaad\Shared\Ledger\CommandBus::class)->dispatch(new Tanaad\Orders\Commands\CreateOrder(
        new CommandContext($this->mats, $actor, null, CarbonImmutable::parse('2026-11-10T10:00:00+03:00')),
        ['order_id' => $seededId, 'client_id' => $client, 'lines' => [['product_id' => ($this->product)('p_rihlaat'), 'bundles' => 1]]],
    ));

    expect(DB::table('orders')->where('id', $seededId)->value('at'))->toBe(DB::table('orders')->where('id', $live)->value('at'))
        ->and((string) DB::table('orders')->where('id', $seededId)->value('at'))->toStartWith('2026-11-10 07:00:00');
    $rows = collect($this->withToken($this->sales)->getJson('/api/v1/sites/mats/orders')->assertOk()->json('orders'))->keyBy('id');
    expect($rows[$live]['createdAt'])->toBe('2026-11-10T07:00:00Z')->and($rows[$seededId]['createdAt'])->toBe('2026-11-10T07:00:00Z');
});
