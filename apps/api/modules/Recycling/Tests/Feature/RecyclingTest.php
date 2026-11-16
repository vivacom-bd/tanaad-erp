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
    $this->rec = (string) Site::query()->where('code', 'recycling')->value('id');
    $this->withToken($this->manager)->postJson('/api/v1/sites/recycling/number-series', ['kind' => 'intake_voucher', 'prefix' => 'IV'])->assertCreated();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function intake(Tests\TestCase $t, string $token, array $body = []): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/recycling/collector-deliveries', [
        'day' => '2026-11-10', 'collector' => 'Mama Aisha', 'grossKg' => 1200, 'tareKg' => 40, 'deductionPct' => 8, 'pricePerKg' => 900, ...$body,
    ]);
}

/** @return array<string, mixed> */
function recyclingRows(): array
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

it('computes net and amount server-side with intakeNet, matching the shared fixtures', function (): void {
    $fixtures = json_decode((string) file_get_contents(base_path('../../contracts/fixtures/arithmetic.json')), true)['intakeNet'];
    foreach ($fixtures as $i => $f) {
        $in = $f['input'][0];
        expect(Arithmetic::intakeNet($in))->toEqual($f['expected']);
        if ($in['tareKg'] >= $in['grossKg']) {
            // The arithmetic clamps to 0, but the gate refuses such a weighing (tare must be under gross).
            intake($this, $this->gate, ['grossKg' => $in['grossKg'], 'tareKg' => $in['tareKg']])->assertStatus(422)->assertJsonPath('details.errors.tare_kg', 'must be less than gross_kg');

            continue;
        }
        $r = intake($this, $this->gate, ['collector' => "C{$i}", 'grossKg' => $in['grossKg'], 'tareKg' => $in['tareKg'],
            'deductionPct' => $in['deductionPct'], 'pricePerKg' => $in['pricePerKg'], 'netKg' => 99999, 'amount' => 1])->assertCreated();
        expect($r->json('delivery.netKg'))->toEqual($f['expected']['netKg'])
            ->and($r->json('delivery.amount'))->toEqual($f['expected']['amount'])
            ->and($r->json('delivery.paid'))->toBeFalse();
    }
    $payload = json_decode((string) DB::table('events')->where('type', 'recycling.collector_intake_recorded')->orderBy('seq')->value('payload'), true);
    expect($payload)->toMatchArray(['net_kg' => 1067.2, 'amount' => 960480, 'voucher_no' => 'IV-00001']);

    $day = $this->withToken($this->ceo)->getJson('/api/v1/sites/recycling/collector-deliveries?day=2026-11-10')->assertOk();
    expect($day->json('deliveries'))->toHaveCount(2)
        ->and($day->json('totals.netKg'))->toEqual(1133.867)
        ->and($day->json('totals.amount'))->toEqual(960580);
});

it('takes the deduction from a configured preset and validates input', function (): void {
    $preset = (string) Str::uuid7();
    DB::table('ref_items')->insert(['id' => $preset, 'site_id' => $this->rec, 'list' => 'deduction_option', 'label' => '5%', 'value' => 5, 'unit' => '%', 'active' => true]);
    $r = $this->withToken($this->gate)->postJson('/api/v1/sites/recycling/collector-deliveries', [
        'collector' => 'Juma', 'grossKg' => 100, 'tareKg' => 0, 'pricePerKg' => 900, 'deductionPresetId' => $preset,
    ])->assertCreated();
    expect($r->json('delivery.deductionPct'))->toEqual(5)->and($r->json('delivery.netKg'))->toEqual(95)->and($r->json('delivery.day'))->toBe('2026-11-10');
    intake($this, $this->gate, ['deductionPresetId' => $preset, 'deductionPct' => 8])->assertStatus(422);
    intake($this, $this->gate, ['deductionPresetId' => (string) Str::uuid7()])->assertStatus(422);
    intake($this, $this->gate, ['grossKg' => 0])->assertStatus(422);
    intake($this, $this->gate, ['deductionPct' => 101])->assertStatus(422);
    intake($this, $this->gate, ['day' => '2026-11-11'])->assertStatus(422);
    $id = (string) Str::uuid7();
    intake($this, $this->gate, ['id' => $id])->assertCreated();
    intake($this, $this->gate, ['id' => $id])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    intake($this, $this->gate, ['grossKg' => 30, 'tareKg' => 40])->assertStatus(422);
});

it('pays a delivery once, booking a materials cash-out that the cash close expects', function (): void {
    $d = intake($this, $this->gate)->json('delivery');
    intake($this, $this->gate, ['collector' => 'Juma', 'grossKg' => 100, 'tareKg' => 0, 'deductionPct' => 33.333, 'pricePerKg' => 1.5])->assertCreated();
    $this->withToken($this->gate)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertForbidden();
    $p = $this->withToken($this->cashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertCreated();
    expect($p->json('delivery.paid'))->toBeTrue()->and($p->json('delivery.paidAmount'))->toEqual(960480)->and($p->json('delivery.paidDay'))->toBe('2026-11-10');
    $this->withToken($this->manager)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    $this->withToken($this->cashier)->postJson('/api/v1/sites/recycling/collector-deliveries/'.Str::uuid7().'/pay')->assertNotFound();

    $ev = json_decode((string) DB::table('events')->where('type', 'recycling.collector_paid')->value('payload'), true);
    expect($ev)->toMatchArray(['delivery_id' => $d['id'], 'collector' => 'Mama Aisha', 'amount' => 960480, 'day' => '2026-11-10', 'voucher_no' => $d['voucherNo']]);

    $book = $this->withToken($this->cashier)->getJson('/api/v1/sites/recycling/cash/days/2026-11-10')->assertOk();
    $entry = collect($book->json('entries'))->firstWhere('category', 'materials');
    expect($entry)->toMatchArray(['direction' => 'out', 'payee' => 'Mama Aisha', 'note' => 'Scrap 1067.2 kg @ 900'])
        ->and($entry['amount'])->toEqual(960480)
        ->and($book->json('totals.expenses'))->toEqual(960480)
        ->and($book->json('totals.expectedClosing'))->toEqual(-960480);
    $close = $this->withToken($this->cashier)->postJson('/api/v1/sites/recycling/cash/days/2026-11-10/close', ['counted' => 0])->assertCreated();
    expect($close->json('day.expectedClosing'))->toEqual(-960480)->and($close->json('day.variance'))->toEqual(960480);

    $ledger = $this->withToken($this->ceo)->getJson('/api/v1/sites/recycling/collector-ledger')->assertOk();
    expect(collect($ledger->json('collectors'))->firstWhere('collector', 'Mama Aisha'))->toMatchArray([
        'deliveries' => 1, 'totalNetKg' => 1067.2, 'totalOwed' => 960480, 'totalPaid' => 960480, 'balance' => 0, 'lastDelivery' => '2026-11-10',
    ]);
    expect(collect($ledger->json('collectors'))->firstWhere('collector', 'Juma'))->toMatchArray(['totalOwed' => 100, 'totalPaid' => 0, 'balance' => 100]);
});

it('rejects a payment while the cash day is closed, until it is reopened', function (): void {
    $d = intake($this, $this->gate)->json('delivery');
    $this->withToken($this->cashier)->postJson('/api/v1/sites/recycling/cash/days/2026-11-10/close', ['counted' => 0])->assertCreated();
    $this->withToken($this->cashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertStatus(409)->assertJsonPath('code', 'day_locked');
    expect(DB::table('collector_deliveries')->where('id', $d['id'])->value('paid'))->toBeFalsy();
    $this->withToken($this->manager)->postJson('/api/v1/sites/recycling/cash/days/2026-11-10/reopen', ['reason' => 'collector came back'])->assertCreated();
    $this->withToken($this->cashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertCreated();
});

it('enforces site and role access', function (): void {
    intake($this, $this->sales)->assertForbidden();
    intake($this, $this->matsCashier)->assertForbidden();
    $d = intake($this, $this->supervisor)->assertCreated()->json('delivery');
    $this->withToken($this->matsCashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertForbidden();
    $this->withToken($this->matsCashier)->getJson('/api/v1/sites/recycling/collector-ledger')->assertForbidden();
    $this->withToken($this->matsCashier)->getJson('/api/v1/sites/recycling/collector-deliveries?day=2026-11-10')->assertForbidden();
    $this->withToken($this->gate)->getJson('/api/v1/sites/recycling/collector-ledger')->assertForbidden();
    $this->withToken($this->gate)->getJson('/api/v1/sites/recycling/collector-deliveries')->assertOk()->assertJsonPath('totals.deliveries', 1);
    $this->withToken($this->ceo)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertForbidden();
});

it('ingests intake and payment offline, assigning the real voucher at sync', function (): void {
    $delivery = (string) Str::uuid7();
    $in = ['id' => (string) Str::uuid7(), 'type' => 'recycling.collector_intake_recorded', 'occurred_at' => '2026-11-10T08:00:00+03:00',
        'payload' => ['delivery_id' => $delivery, 'day' => '2026-11-10', 'collector' => 'Okello', 'gross_kg' => 50, 'tare_kg' => 2,
            'deduction_pct' => 0, 'price_per_kg' => 850, 'net_kg' => 1, 'provisional_no' => 'TMP-7', 'scale_photo_hash' => str_repeat('a', 64)]];
    $pay = ['id' => (string) Str::uuid7(), 'type' => 'recycling.collector_paid', 'occurred_at' => '2026-11-10T08:05:00+03:00', 'payload' => ['delivery_id' => $delivery]];
    $again = [...$pay, 'id' => (string) Str::uuid7()];
    $r = $this->withToken($this->cashier)->postJson('/api/v1/sites/recycling/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$in, $in, $pay, $again]])->assertOk();

    expect($r->json('results.0.status'))->toBe('accepted')
        ->and($r->json('results.1.status'))->toBe('duplicate')
        ->and($r->json('results.2.status'))->toBe('accepted')
        ->and($r->json('results.3.status'))->toBe('rejected')
        ->and($r->json('results.3.code'))->toBe('state_conflict');
    $row = DB::table('collector_deliveries')->where('id', $delivery)->first();
    expect($row->voucher_no)->toBe('IV-00001')->and($row->provisional_no)->toBe('TMP-7')->and((float) $row->net_kg)->toBe(48.0)
        ->and((bool) $row->has_scale_photo)->toBeTrue()->and((bool) $row->paid)->toBeTrue()
        ->and((float) DB::table('cash_entries')->where('category', 'materials')->value('amount'))->toBe(40800.0);
});

it('keeps voucher numbers and the cash link exact across a replay', function (): void {
    $a = intake($this, $this->gate)->json('delivery');
    $b = intake($this, $this->gate, ['collector' => 'Juma'])->json('delivery');
    expect([$a['voucherNo'], $b['voucherNo']])->toBe(['IV-00001', 'IV-00002']);
    $this->withToken($this->cashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$b['id']}/pay")->assertCreated();

    $before = recyclingRows();
    app(Replay::class)->rebuild();
    expect(recyclingRows())->toEqual($before);
    app(Replay::class)->rebuild();
    expect(recyclingRows())->toEqual($before);

    // The series counter is not rewound by a replay, so the next intake gets IV-00003.
    expect((int) DB::table('number_series')->where(['site_id' => $this->rec, 'kind' => 'intake_voucher'])->value('next'))->toBe(3);
});

it('rejects tare at or over gross and oversized numbers without burning a voucher', function (): void {
    intake($this, $this->gate, ['grossKg' => 100, 'tareKg' => 100])->assertStatus(422)->assertJsonPath('details.errors.tare_kg', 'must be less than gross_kg');
    intake($this, $this->gate, ['grossKg' => 100, 'tareKg' => 120])->assertStatus(422)->assertJsonPath('details.errors.tare_kg', 'must be less than gross_kg');
    intake($this, $this->gate, ['grossKg' => 1e12, 'tareKg' => 0])->assertStatus(422)->assertJsonPath('details.errors.gross_kg', 'must be at most 10000000');
    intake($this, $this->gate, ['pricePerKg' => 1e15])->assertStatus(422)->assertJsonPath('details.errors.price_per_kg', 'must be at most 1000000');
    expect(intake($this, $this->gate)->assertCreated()->json('delivery.voucherNo'))->toBe('IV-00001');
});

it('validates the day query as a real date', function (): void {
    $this->withToken($this->manager)->getJson('/api/v1/sites/recycling/collector-deliveries?day=2026-02-30')->assertStatus(422)->assertJsonPath('details.errors.day', 'must be a date (YYYY-MM-DD)');
});

it('carries the scale photo flag in the payment event and refuses a future pay day', function (): void {
    $d = intake($this, $this->gate, ['hasScalePhoto' => true])->json('delivery');
    $future = ['id' => (string) Str::uuid7(), 'type' => 'recycling.collector_paid', 'occurred_at' => '2026-11-12T08:00:00+03:00', 'payload' => ['delivery_id' => $d['id']]];
    $r = $this->withToken($this->cashier)->postJson('/api/v1/sites/recycling/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$future]])->assertOk();
    expect($r->json('results.0.status'))->toBe('rejected')->and(DB::table('collector_deliveries')->where('id', $d['id'])->value('paid'))->toBeFalsy();

    $this->withToken($this->cashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$d['id']}/pay")->assertCreated();
    $ev = json_decode((string) DB::table('events')->where('type', 'recycling.collector_paid')->value('payload'), true);
    expect($ev['has_scale_photo'])->toBeTrue()
        ->and((bool) DB::table('cash_entries')->where('category', 'materials')->value('has_receipt_photo'))->toBeTrue();
});
