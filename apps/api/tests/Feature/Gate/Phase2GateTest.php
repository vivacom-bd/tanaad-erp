<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;

/** Phase 2 gate, HTTP only: below-benchmark exception to the CEO, finishing label stock, recycling intake voucher + pay, site isolation. */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00:00', 'Africa/Kampala'));
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function gate2Login(Tests\TestCase $t, string $username): string
{
    return (string) $t->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'password'])->assertCreated()->json('token');
}

/** @return array<string, mixed> */
function gate2Event(string $type, array $payload, string $at): array
{
    return ['id' => (string) Str::uuid7(), 'type' => $type, 'occurred_at' => $at, 'payload' => $payload];
}

function gate2Send(Tests\TestCase $t, string $token, string $site, array $events, ?string $batch = null): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/events", ['batch_id' => $batch ?? (string) Str::uuid7(), 'events' => $events]);
}

it('passes the Phase 2 gate end to end', function (): void {
    $at = '2026-11-10T09:00:00+03:00';

    // (a) Mats supervisor logs a loom below benchmark with no reason, offline.
    $sup = gate2Login($this, 'supmats');
    $loom1 = (string) DB::table('machines')->where('code_key', 'm_loom1')->value('id');
    $loom2 = (string) DB::table('machines')->where('code_key', 'm_loom2')->value('id');
    $product = (string) DB::table('products')->where('code', 'p_rihlaat')->value('id');
    $low = gate2Event('production.entry_recorded', ['machine_id' => $loom1, 'shift' => ['day' => '2026-11-07', 'kind' => 'day'], 'product_id' => $product, 'good_units' => 40], $at);
    $withReason = gate2Event('production.entry_recorded', ['machine_id' => $loom1, 'shift' => ['day' => '2026-11-07', 'kind' => 'night'], 'product_id' => $product, 'good_units' => 40,
        'downtime_min' => 200, 'downtime_reason' => 'Power cut'], $at);
    $res = gate2Send($this, $sup, 'mats', [$low, $withReason])->assertOk();
    expect(array_column($res->json('results'), 'status'))->toBe(['accepted', 'accepted']);

    $ceo = gate2Login($this, 'ceo1');
    $list = $this->withToken($ceo)->getJson('/api/v1/exceptions?kind=below_benchmark&status=open')->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0])->toMatchArray(['kind' => 'below_benchmark', 'status' => 'open', 'siteCode' => 'mats'])
        ->and($list[0]['subjectRef'])->toBe($loom1.'|sh_mats_2026-11-07_day');

    // (b) Storekeeper prints a finishing bundle label offline; replay is a duplicate.
    $store = gate2Login($this, 'storemats');
    $labelEvent = gate2Event('labels.created', ['label_id' => (string) Str::uuid7(), 'kind' => 'bundle', 'stage_code' => 'finish', 'product_id' => $product, 'qty_units' => 50, 'qty_kg' => 20.25], $at);
    $batch = (string) Str::uuid7();
    $first = gate2Send($this, $store, 'mats', [$labelEvent], $batch)->assertOk();
    expect($first->json('results.0.status'))->toBe('accepted');
    $second = gate2Send($this, $store, 'mats', [$labelEvent], $batch)->assertOk();
    expect($second->json('results.0.status'))->toBe('duplicate')->and(DB::table('labels')->count())->toBe(1);

    $stock = $this->withToken($ceo)->getJson('/api/v1/sites/mats/label-stock')->assertOk()->json('stock');
    expect($stock)->toHaveCount(1)->and($stock[0]['bundles'])->toBe(1)->and($stock[0]['product']['id'])->toBe($product);
    $code = (string) DB::table('labels')->value('code');
    $hist = $this->withToken($ceo)->getJson("/api/v1/labels/{$code}/history")->assertOk();
    expect(array_column($hist->json('history'), 'type'))->toBe(['created'])->and($hist->json('label.code'))->toBe($code);

    // (c) Recycling: voucher number assigned at sync, same on replay; pay books to the cash book.
    $mgr = gate2Login($this, 'smrec');
    $this->withToken($mgr)->postJson('/api/v1/sites/recycling/number-series', ['kind' => 'intake_voucher', 'prefix' => 'IV'])->assertCreated();
    $recSup = gate2Login($this, 'suprec');
    $deliveryId = (string) Str::uuid7();
    $intake = gate2Event('recycling.collector_intake_recorded', ['delivery_id' => $deliveryId, 'day' => '2026-11-10', 'collector' => 'Mama Aisha',
        'gross_kg' => 1200, 'tare_kg' => 40, 'deduction_pct' => 8, 'price_per_kg' => 900], $at);
    $rb = (string) Str::uuid7();
    expect(gate2Send($this, $recSup, 'recycling', [$intake], $rb)->assertOk()->json('results.0.status'))->toBe('accepted');
    expect(gate2Send($this, $recSup, 'recycling', [$intake], $rb)->assertOk()->json('results.0.status'))->toBe('duplicate');
    expect(DB::table('collector_deliveries')->count())->toBe(1);

    $day = $this->withToken($recSup)->getJson('/api/v1/sites/recycling/collector-deliveries?day=2026-11-10')->assertOk();
    expect($day->json('deliveries'))->toHaveCount(1)->and($day->json('deliveries.0.voucherNo'))->toBe('IV-00001')
        ->and($day->json('deliveries.0.paid'))->toBeFalse();

    $cashier = gate2Login($this, 'cashrec');
    $this->withToken($cashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$deliveryId}/pay")->assertCreated();
    $book = $this->withToken($cashier)->getJson('/api/v1/sites/recycling/cash/days/2026-11-10')->assertOk();
    $entry = collect($book->json('entries'))->firstWhere('category', 'materials');
    expect($entry)->toMatchArray(['direction' => 'out', 'payee' => 'Mama Aisha'])->and($entry['amount'])->toEqual(960480);
    // A closed cash day refuses further payment.
    $other = gate2Event('recycling.collector_intake_recorded', ['delivery_id' => (string) Str::uuid7(), 'day' => '2026-11-10', 'collector' => 'Juma',
        'gross_kg' => 100, 'tare_kg' => 0, 'deduction_pct' => 0, 'price_per_kg' => 900], $at);
    gate2Send($this, $recSup, 'recycling', [$other])->assertOk();
    $otherId = (string) DB::table('collector_deliveries')->where('collector', 'Juma')->value('id');
    $this->withToken($cashier)->postJson('/api/v1/sites/recycling/cash/days/2026-11-10/close', ['counted' => 0])->assertCreated();
    $this->withToken($cashier)->postJson("/api/v1/sites/recycling/collector-deliveries/{$otherId}/pay")->assertStatus(409)->assertJsonPath('code', 'day_locked');

    // (d) Cross-site isolation.
    $this->withToken($sup)->getJson('/api/v1/sites/recycling/collector-deliveries?day=2026-11-10')->assertForbidden();
    $this->withToken($sup)->postJson('/api/v1/sites/recycling/collector-deliveries', ['collector' => 'X', 'grossKg' => 10, 'tareKg' => 0, 'pricePerKg' => 1, 'deductionPct' => 0])->assertForbidden();
    gate2Send($this, $sup, 'recycling', [$other])->assertForbidden();
    $this->withToken($recSup)->getJson('/api/v1/sites/mats/label-stock')->assertForbidden();
});
