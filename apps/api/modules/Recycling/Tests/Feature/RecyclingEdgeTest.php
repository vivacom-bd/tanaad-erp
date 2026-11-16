<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00:00', 'Africa/Kampala'));
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->gate = $token('u_store_rec');
    $this->supervisor = $token('u_sup_rec');
    $this->manager = $token('u_sm_rec');
    $this->cashier = $token('u_cash_rec');
    $this->sales = $token('u_sales_rec');
    $this->ceo = $token('u_ceo1');
    $this->matsCashier = $token('u_cash_mats');
    $this->matsSup = $token('u_sup_mats');
    $this->rec = (string) Site::query()->where('code', 'recycling')->value('id');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->withToken($this->manager)->postJson('/api/v1/sites/recycling/number-series', ['kind' => 'intake_voucher', 'prefix' => 'IV'])->assertCreated();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function edgeIntake(Tests\TestCase $t, string $token, array $body = []): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/recycling/collector-deliveries', [
        'day' => '2026-11-10', 'collector' => 'Mama Aisha', 'grossKg' => 1200, 'tareKg' => 40, 'deductionPct' => 8, 'pricePerKg' => 900, ...$body,
    ]);
}

function edgePay(Tests\TestCase $t, string $token, string $id, array $body = []): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/recycling/collector-deliveries/{$id}/pay", $body);
}

/** @return array<string, mixed> */
function edgeRecyclingRows(): array
{
    $strip = fn ($rows) => $rows->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();

    return [
        'deliveries' => $strip(DB::table('collector_deliveries')->orderBy('id')->get()),
        'cash' => $strip(DB::table('cash_entries')->orderBy('id')->get()),
        'days' => $strip(DB::table('cash_days')->orderBy('id')->get()),
    ];
}

it('rejects malformed intake fields with 422 field errors', function (): void {
    foreach (['grossKg', 'pricePerKg'] as $f) {
        foreach ([0, -1, 'abc', 'NaN', [], null, true, ''] as $bad) {
            edgeIntake($this, $this->gate, [$f => $bad])->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
        }
    }
    foreach ([-1, 'abc', 'NaN', [], true] as $bad) {
        edgeIntake($this, $this->gate, ['tareKg' => $bad])->assertStatus(422);
    }
    foreach ([-0.001, 100.001, 101, 1e6, 'abc', 'NaN', [], true] as $bad) {
        edgeIntake($this, $this->gate, ['deductionPct' => $bad])->assertStatus(422);
    }
    edgeIntake($this, $this->gate, ['deductionPct' => null])->assertStatus(422);
    foreach (['grossKg', 'tareKg', 'pricePerKg', 'deductionPct', 'collector'] as $missing) {
        $body = ['day' => '2026-11-10', 'collector' => 'X', 'grossKg' => 10, 'tareKg' => 1, 'deductionPct' => 8, 'pricePerKg' => 900];
        unset($body[$missing]);
        $this->withToken($this->gate)->postJson('/api/v1/sites/recycling/collector-deliveries', $body)->assertStatus(422);
    }
    foreach (['', '   ', str_repeat('x', 161)] as $bad) {
        edgeIntake($this, $this->gate, ['collector' => $bad])->assertStatus(422);
    }
    foreach (['2026-11-11', '2027-01-01', 'yesterday', '2026-02-30', '10/11/2026', 20261110] as $bad) {
        edgeIntake($this, $this->gate, ['day' => $bad])->assertStatus(422);
    }
    edgeIntake($this, $this->gate, ['id' => 'nope'])->assertStatus(422);
    edgeIntake($this, $this->gate, ['hasScalePhoto' => 'maybe'])->assertStatus(422);
    edgeIntake($this, $this->gate, ['scalePhotoHash' => str_repeat('a', 129)])->assertStatus(422);
    edgeIntake($this, $this->gate, ['provisionalNo' => str_repeat('a', 65)])->assertStatus(422);
    $err = edgeIntake($this, $this->gate, ['grossKg' => -1, 'pricePerKg' => 0, 'collector' => ''])->assertStatus(422)->json();
    expect(json_encode($err))->toContain('gross_kg')->toContain('price_per_kg')->toContain('collector');
    expect(DB::table('collector_deliveries')->count())->toBe(0)
        ->and((int) DB::table('number_series')->where(['site_id' => $this->rec, 'kind' => 'intake_voucher'])->value('next'))->toBe(1);
});

it('validates deduction presets: unknown, inactive, other site, mismatched', function (): void {
    $mk = function (array $over): string {
        $id = (string) Str::uuid7();
        DB::table('ref_items')->insert(['id' => $id, 'site_id' => $this->rec, 'list' => 'deduction_option', 'label' => 'p', 'value' => 5, 'unit' => '%', 'active' => true, ...$over]);

        return $id;
    };
    $ok = $mk([]);
    $inactive = $mk(['active' => false]);
    $other = $mk(['site_id' => $this->mats]);
    $wrongList = $mk(['list' => 'waste_source']);
    $noValue = $mk(['value' => null]);
    $group = $mk(['site_id' => null, 'value' => 3]);
    foreach ([$inactive, $other, $wrongList, $noValue, (string) Str::uuid7(), 'nope'] as $bad) {
        edgeIntake($this, $this->gate, ['deductionPct' => null, 'deductionPresetId' => $bad])->assertStatus(422);
        edgeIntake($this, $this->gate, ['deductionPresetId' => $bad])->assertStatus(422);
    }
    edgeIntake($this, $this->gate, ['deductionPresetId' => $ok, 'deductionPct' => 4.999])->assertStatus(422);
    edgeIntake($this, $this->gate, ['deductionPresetId' => $ok, 'deductionPct' => 5.0004])->assertCreated();
    expect(edgeIntake($this, $this->gate, ['deductionPct' => null, 'deductionPresetId' => $group])->assertCreated()->json('delivery.deductionPct'))->toEqual(3);
});

it('rounds money half-up to whole shillings and handles tare and deduction boundaries', function (): void {
    $cases = [
        // gross, tare, ded, price => net, amount
        [1, 0, 0, 0.5, 1, 0.5],       // intake amount keeps cents; payment rounds (see pay test)
        [3, 0, 0, 0.5, 3, 1.5],
        [5, 0, 0, 0.5, 5, 2.5],
        [1, 0, 0, 0.49, 1, 0.49],
        [10, 0, 0, 900, 10, 9000],    // 0 % deduction
        [10, 0, 100, 900, 0, 0],      // 100 % deduction
        [10, 9.999, 0, 900, 0.001, 0.9],
        [100, 0, 0.0005, 1, 100, 100],  // deduction below 3dp precision
        [0.001, 0, 0, 1, 0.001, 0],   // 0.001 shillings rounds to 0.00
    ];
    // A tare that is not below the gross weight is a mistake at the scale, not a zero delivery.
    foreach ([[10, 10], [10, 10.001], [10, 11]] as [$gross, $tare]) {
        edgeIntake($this, $this->gate, ['grossKg' => $gross, 'tareKg' => $tare])->assertStatus(422);
    }
    edgeIntake($this, $this->gate, ['grossKg' => 10, 'tareKg' => 9.999])->assertCreated();
    foreach ($cases as $i => [$gross, $tare, $ded, $price, $net, $amount]) {
        $expected = Arithmetic::intakeNet(['grossKg' => $gross, 'tareKg' => $tare, 'deductionPct' => $ded, 'pricePerKg' => $price]);
        $r = edgeIntake($this, $this->gate, ['collector' => "R{$i}", 'grossKg' => $gross, 'tareKg' => $tare, 'deductionPct' => $ded, 'pricePerKg' => $price])->assertCreated();
        expect($r->json('delivery.netKg'))->toEqual($net, "net of case {$i}")->and($r->json('delivery.amount'))->toEqual($amount, "amount of case {$i}")
            ->and($expected)->toEqual(['netKg' => $net, 'amount' => $amount]);
    }
});

it('pays zero-amount deliveries and rounds the payment as the intake did', function (): void {
    $zero = edgeIntake($this, $this->gate, ['collector' => 'Z', 'grossKg' => 10, 'tareKg' => 0, 'deductionPct' => 100])->assertCreated()->json('delivery');
    expect($zero['amount'])->toEqual(0);
    edgePay($this, $this->cashier, $zero['id'])->assertCreated()->assertJsonPath('delivery.paid', true);
    $half = edgeIntake($this, $this->gate, ['collector' => 'H', 'grossKg' => 5, 'tareKg' => 0, 'deductionPct' => 0, 'pricePerKg' => 0.5])->assertCreated()->json('delivery');
    $p = edgePay($this, $this->cashier, $half['id'])->assertCreated();
    expect($p->json('delivery.paidAmount'))->toEqual(3)->and((float) DB::table('cash_entries')->where('payee', 'H')->value('amount'))->toBe(3.0);
});

it('rejects over-wide and non-finite intake values with 422, not 500', function (): void {
    edgeIntake($this, $this->gate, ['grossKg' => 1e12])->assertStatus(422);
});

it('rejects the numeric string 1e999 with 422, not 500', function (): void {
    edgeIntake($this, $this->gate, ['grossKg' => '1e999'])->assertStatus(422);
});

it('rejects an amount wider than its column with 422, not 500', function (): void {
    edgeIntake($this, $this->gate, ['grossKg' => 99999999, 'tareKg' => 0, 'deductionPct' => 0, 'pricePerKg' => 99999999])->assertStatus(422);
});

it('conflicts on duplicate delivery ids, also across sites, without consuming a voucher', function (): void {
    $id = (string) Str::uuid7();
    edgeIntake($this, $this->gate, ['id' => $id])->assertCreated();
    edgeIntake($this, $this->gate, ['id' => $id, 'collector' => 'Other'])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    edgeIntake($this, $this->gate, ['deliveryId' => $id])->assertStatus(409);
    edgeIntake($this, $this->gate, ['grossKg' => -1])->assertStatus(422);
    expect(edgeIntake($this, $this->gate)->json('delivery.voucherNo'))->toBe('IV-00002');
});

it('pays once, and refuses unknown, other-site and malformed payments', function (): void {
    $d = edgeIntake($this, $this->gate)->json('delivery');
    edgePay($this, $this->cashier, $d['id'], ['paymentId' => 'nope'])->assertStatus(422);
    edgePay($this, $this->cashier, 'not-a-uuid')->assertStatus(422);
    edgePay($this, $this->cashier, (string) Str::uuid7())->assertNotFound();
    // The delivery belongs to recycling: another site's cashier at their own site cannot see it.
    $this->withToken($this->matsCashier)->postJson("/api/v1/sites/mats/collector-deliveries/{$d['id']}/pay")->assertNotFound();
    expect(DB::table('collector_deliveries')->where('id', $d['id'])->value('paid'))->toBeFalsy();

    $payId = (string) Str::uuid7();
    edgePay($this, $this->cashier, $d['id'], ['paymentId' => $payId])->assertCreated();
    edgePay($this, $this->cashier, $d['id'])->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    edgePay($this, $this->manager, $d['id'], ['paymentId' => $payId])->assertStatus(409);
    $other = edgeIntake($this, $this->gate, ['collector' => 'Juma'])->json('delivery');
    edgePay($this, $this->cashier, $other['id'], ['paymentId' => $payId])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    expect(DB::table('cash_entries')->where('category', 'materials')->count())->toBe(1)
        ->and(DB::table('events')->where('type', 'recycling.collector_paid')->count())->toBe(1);
});

it('enforces roles and sites on every write, read and snapshot', function (): void {
    $d = edgeIntake($this, $this->supervisor)->assertCreated()->json('delivery');
    foreach ([$this->manager, $this->gate, $this->cashier] as $t) {
        edgeIntake($this, $t, ['collector' => 'W'.Str::random(4)])->assertCreated();
    }
    foreach ([$this->sales, $this->matsCashier, $this->matsSup, $this->ceo] as $t) {
        edgeIntake($this, $t)->assertForbidden();
        edgePay($this, $t, $d['id'])->assertForbidden();
    }
    foreach ([$this->gate, $this->supervisor, $this->sales] as $t) {
        edgePay($this, $t, $d['id'])->assertForbidden();
    }
    foreach ([$this->sales, $this->matsCashier, $this->matsSup] as $t) {
        $this->withToken($t)->getJson('/api/v1/sites/recycling/collector-deliveries')->assertForbidden();
        $this->withToken($t)->getJson('/api/v1/sites/recycling/collector-ledger')->assertForbidden();
    }
    foreach ([$this->matsCashier, $this->matsSup] as $t) {
        $this->withToken($t)->getJson('/api/v1/sites/recycling/snapshots/collector_deliveries?since=0')->assertForbidden();
    }
    $this->withToken($this->gate)->getJson('/api/v1/sites/recycling/collector-ledger')->assertForbidden();
    $this->withToken($this->ceo)->getJson('/api/v1/sites/recycling/collector-ledger')->assertOk();
    $this->withToken($this->ceo)->getJson('/api/v1/sites/recycling/collector-deliveries')->assertOk();
    $this->withToken($this->ceo)->getJson('/api/v1/sites/recycling/snapshots/collector_deliveries?since=0')->assertOk();
    // A recycling cashier aimed at mats.
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/collector-deliveries', ['collector' => 'X', 'grossKg' => 1, 'tareKg' => 0, 'deductionPct' => 0, 'pricePerKg' => 1])->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/collector-ledger')->assertForbidden();
    $this->flushHeaders()->getJson('/api/v1/sites/recycling/collector-deliveries')->assertUnauthorized();
    $this->flushHeaders()->postJson('/api/v1/sites/recycling/collector-deliveries', [])->assertUnauthorized();
    expect(DB::table('collector_deliveries')->count())->toBe(4)->and(DB::table('cash_entries')->count())->toBe(0);
});

it('keeps the collector snapshot to roles that may read deliveries', function (): void {
    $this->withToken($this->sales)->getJson('/api/v1/sites/recycling/snapshots/collector_deliveries?since=0')->assertForbidden();
});

it('validates the day parameter of the deliveries read', function (): void {
    foreach (['nope', '2026-1-1', '2026-11-10x'] as $bad) {
        $this->withToken($this->ceo)->getJson("/api/v1/sites/recycling/collector-deliveries?day={$bad}")->assertStatus(422);
    }
    $r = $this->withToken($this->ceo)->getJson('/api/v1/sites/recycling/collector-deliveries?day=2026-01-01')->assertOk();
    expect($r->json('deliveries'))->toBe([])->and($r->json('totals.deliveries'))->toBe(0);
});

it('handles an offline batch mixing valid, invalid, duplicate and conflicting events', function (): void {
    $ev = fn (string $type, array $payload, ?string $id = null) => ['id' => $id ?? (string) Str::uuid7(), 'type' => $type, 'occurred_at' => '2026-11-10T08:00:00+03:00', 'payload' => $payload];
    $delivery = (string) Str::uuid7();
    $intake = fn (array $o = []) => $ev('recycling.collector_intake_recorded', ['delivery_id' => (string) Str::uuid7(), 'day' => '2026-11-10', 'collector' => 'O',
        'gross_kg' => 50, 'tare_kg' => 2, 'deduction_pct' => 0, 'price_per_kg' => 850, ...$o]);
    $good = $intake(['delivery_id' => $delivery]);
    $sameFact = $intake(['delivery_id' => $delivery]);
    $neg = $intake(['gross_kg' => -1]);
    $future = $intake(['day' => '2026-11-12']);
    $noDed = $intake();
    unset($noDed['payload']['deduction_pct']);
    $pay = $ev('recycling.collector_paid', ['delivery_id' => $delivery]);
    $payAgain = $ev('recycling.collector_paid', ['delivery_id' => $delivery]);
    $payUnknown = $ev('recycling.collector_paid', ['delivery_id' => (string) Str::uuid7()]);
    $payBad = $ev('recycling.collector_paid', []);
    $unknown = $ev('recycling.exploded', []);
    $r = $this->withToken($this->cashier)->postJson('/api/v1/sites/recycling/events', ['batch_id' => (string) Str::uuid7(),
        'events' => [$good, $good, $sameFact, $neg, $future, $noDed, $pay, $pay, $payAgain, $payUnknown, $payBad, $unknown]])->assertOk();
    expect(array_column($r->json('results'), 'status'))->toBe(['accepted', 'duplicate', 'rejected', 'rejected', 'rejected', 'rejected', 'accepted', 'duplicate', 'rejected', 'rejected', 'rejected', 'rejected'])
        ->and($r->json('results.2.code'))->toBe('duplicate_fact')->and($r->json('results.3.code'))->toBe('invalid_payload')
        ->and($r->json('results.8.code'))->toBe('state_conflict')->and($r->json('results.9.code'))->toBe('not_found')->and($r->json('results.10.code'))->toBe('invalid_payload');
    expect(DB::table('collector_deliveries')->count())->toBe(1)->and(DB::table('cash_entries')->where('category', 'materials')->count())->toBe(1);

    // Roles are checked per event: a gate keeper may weigh in, not pay; another site's users do neither.
    $mixed = $this->withToken($this->gate)->postJson('/api/v1/sites/recycling/events', ['batch_id' => (string) Str::uuid7(),
        'events' => [$intake(), $ev('recycling.collector_paid', ['delivery_id' => $delivery])]]);
    expect($mixed->status() === 403 || ($mixed->json('results.0.status') === 'accepted' && $mixed->json('results.1.status') === 'rejected'))->toBeTrue();
    $foreign = $this->withToken($this->matsCashier)->postJson('/api/v1/sites/recycling/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$intake()]]);
    expect($foreign->status() === 403 || $foreign->json('results.0.status') === 'rejected')->toBeTrue();
    expect(DB::table('collector_deliveries')->count())->toBeLessThanOrEqual(2);
});

it('closes the day lock for payments only, and rebuilds exactly after the edge cases', function (): void {
    $a = edgeIntake($this, $this->gate, ['grossKg' => 5, 'tareKg' => 0, 'deductionPct' => 0, 'pricePerKg' => 0.5])->json('delivery');
    $b = edgeIntake($this, $this->gate, ['collector' => 'Juma', 'grossKg' => 100, 'tareKg' => 0, 'deductionPct' => 33.333, 'pricePerKg' => 1.5, 'scalePhotoHash' => str_repeat('b', 64)])->json('delivery');
    edgeIntake($this, $this->gate, ['grossKg' => 0])->assertStatus(422);
    edgePay($this, $this->cashier, $a['id'])->assertCreated();
    edgePay($this, $this->cashier, $a['id'])->assertStatus(409);
    $this->withToken($this->cashier)->postJson('/api/v1/sites/recycling/cash/days/2026-11-10/close', ['counted' => 0])->assertCreated();
    edgePay($this, $this->cashier, $b['id'])->assertStatus(409)->assertJsonPath('code', 'day_locked');
    // A weigh-in does not touch cash, so it is still accepted on a closed cash day.
    edgeIntake($this, $this->gate, ['collector' => 'Late'])->assertCreated();

    $before = edgeRecyclingRows();
    app(Replay::class)->rebuild();
    expect(edgeRecyclingRows())->toEqual($before);
    app(Replay::class)->rebuild();
    expect(edgeRecyclingRows())->toEqual($before)
        ->and((int) DB::table('number_series')->where(['site_id' => $this->rec, 'kind' => 'intake_voucher'])->value('next'))->toBe(4);
});
