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
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00:00', 'Africa/Kampala'));
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->sup = $token('u_sup_mats');
    $this->manager = $token('u_sm_mats');
    $this->ceo = $token('u_ceo1');
    $this->bagsSup = $token('u_sup_bags');
    $this->cashier = $token('u_cash_mats');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $m = fn (string $key): string => (string) DB::table('machines')->where('code_key', $key)->value('id');
    $this->loom1 = $m('m_loom1');
    $this->loom2 = $m('m_loom2');
    $this->mixer = $m('m_mixer');
    $this->rihlaat = (string) DB::table('products')->where('code', 'p_rihlaat')->value('id');   // loom benchmark 76/shift, site benchmark_pct 80
    $this->waste = (string) DB::table('ref_items')->where(['list' => 'waste_source', 'site_id' => $this->mats])->value('code');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function logEntry(Tests\TestCase $t, string $token, array $body, string $site = 'mats'): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/production/entries", $body);
}

/** @return array<string, mixed> */
function productionRows(): array
{
    $strip = fn ($rows) => $rows->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();

    return [
        'entries' => $strip(DB::table('production_entries')->orderBy('id')->get()),
        'batches' => $strip(DB::table('production_batches')->orderBy('id')->get()),
        'closed' => $strip(DB::table('production_shifts_closed')->orderBy('id')->get()),
        'waste' => $strip(DB::table('waste_weighings')->orderBy('id')->get()),
        'exceptions' => $strip(DB::table('exceptions')->orderBy('id')->get()),
    ];
}

it('raises below_benchmark for a loom under benchmark with no reason, nothing with a reason, and resolves on a good re-log', function (): void {
    // A past shift is judged against the full benchmark (progress 1).
    $shift = ['day' => '2026-11-09', 'kind' => 'day'];
    $r = logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 40])->assertCreated();
    expect($r->json('payload.below_benchmark'))->toBeTrue()->and($r->json('payload.shift_id'))->toBe('sh_mats_2026-11-09_day');

    $list = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/exceptions')->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0]['kind'])->toBe('below_benchmark')->and($list[0]['ownerRole'])->toBe('supervisor')
        ->and($list[0]['subjectRef'])->toBe($this->loom1.'|sh_mats_2026-11-09_day')->and($list[0]['status'])->toBe('open');

    logEntry($this, $this->sup, ['machineId' => $this->loom2, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 10, 'downtimeMin' => 200, 'downtimeReason' => 'Power cut'])->assertCreated();
    expect(DB::table('exceptions')->count())->toBe(1);

    // Re-log still below with no reason: same row, no second exception.
    $again = logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 45])->assertCreated();
    expect($again->json('payload.entry_id'))->toBe($r->json('payload.entry_id'))->and(DB::table('exceptions')->count())->toBe(1)
        ->and(DB::table('production_entries')->count())->toBe(2);

    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 70])->assertCreated();
    expect(DB::table('exceptions')->value('status'))->toBe('resolved')
        ->and((int) DB::table('production_entries')->where('id', $r->json('payload.entry_id'))->value('good_units'))->toBe(70);
});

it('raises a below_benchmark per machine and shift, and a re-log resolves only its own shift', function (): void {
    $day = ['day' => '2026-11-09', 'kind' => 'day'];
    $night = ['day' => '2026-11-09', 'kind' => 'night'];
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => 40])->assertCreated();
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $night, 'productId' => $this->rihlaat, 'goodUnits' => 30])->assertCreated();
    expect(DB::table('exceptions')->where('kind', 'below_benchmark')->where('status', 'open')->count())->toBe(2);

    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $night, 'productId' => $this->rihlaat, 'goodUnits' => 70])->assertCreated();
    expect(DB::table('exceptions')->where('kind', 'below_benchmark')->where('status', 'open')->count())->toBe(1)
        ->and(DB::table('exceptions')->where('kind', 'below_benchmark')->where('status', 'resolved')->count())->toBe(1);
});

it('shows the machine wall and mass balance', function (): void {
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 40, 'outputKg' => 400])->assertCreated();
    logEntry($this, $this->sup, ['machineId' => $this->loom2, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 30, 'downtimeReason' => 'Yarn break', 'outputKg' => 300])->assertCreated();
    logEntry($this, $this->sup, ['machineId' => $this->mixer, 'shift' => $shift, 'inputKg' => 1000, 'outputKg' => 1000])->assertCreated();

    $wall = $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-10&shift=day')->assertOk();
    $tiles = collect($wall->json('machines'))->keyBy('machine.id');
    // 10:00 is 3 of 12 hours into the day shift: expected so far round(76 × 0.25) = 19.
    expect($wall->json('machines'))->toHaveCount(21)
        ->and($tiles[$this->loom1]['state'])->toBe('ok')->and($tiles[$this->loom1]['expectedSoFar'])->toBe(19)->and($tiles[$this->loom1]['benchmark'])->toEqual(76)
        ->and($tiles[$this->loom2]['state'])->toBe('ok')
        ->and($tiles[(string) DB::table('machines')->where('code_key', 'm_loom3')->value('id')]['state'])->toBe('not_logged')
        ->and($tiles[(string) DB::table('machines')->where('code_key', 'm_loom16')->value('id')]['state'])->toBe('arriving');
    $night = $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-09&shift=night')->assertOk();
    expect(collect($night->json('machines'))->where('state', 'not_logged'))->toHaveCount(15);

    $mb = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/mass-balance?day=2026-11-10')->assertOk();
    // in 1000, out 700, allowed 2% = 20 kg → gap 280 kg (28%) missing.
    expect($mb->json())->toMatchArray(['kgIn' => 1000, 'kgOut' => 700, 'wasteKg' => 0, 'gapKg' => 280, 'gapPct' => 28, 'state' => 'missing']);

    $out = $this->withToken($this->sup)->getJson('/api/v1/sites/mats/production/output?day=2026-11-10')->assertOk();
    expect($out->json('byProduct.0.units'))->toBe(70)->and($out->json('byProduct.0.target'))->toEqual(152);
});

it('keeps a raw-issue entry of the same machine off the wall and out of its line yield', function (): void {
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 40, 'inputKg' => 420, 'outputKg' => 400])->assertCreated();
    // Logged after the weave entry, on the same loom and shift, at another stage.
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'stageCode' => 'raw', 'inputKg' => 900, 'outputKg' => 900])->assertCreated();

    $tile = collect($this->withToken($this->ceo)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-10&shift=day')->assertOk()->json('machines'))
        ->firstWhere('machine.id', $this->loom1);
    expect($tile['entry']['stageCode'])->toBe('weave')->and($tile['goodUnits'])->toBe(40);
    $line = collect(app(Tanaad\Production\Queries\ProductionQuery::class)->yieldByLine($this->mats, '2026-11-10'))->firstWhere('machineId', $this->loom1);
    expect($line['inKg'])->toEqual(420)->and($line['outKg'])->toEqual(400);
});

it('closes a shift raising not_logged per unlogged machine and mass_gap once per day', function (): void {
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    logEntry($this, $this->sup, ['machineId' => $this->mixer, 'shift' => $shift, 'inputKg' => 1000, 'outputKg' => 1000])->assertCreated();
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 76, 'outputKg' => 900])->assertCreated();

    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/shifts/close', $shift)->assertCreated();
    expect(DB::table('exceptions')->where('kind', 'not_logged')->count())->toBe(14)
        ->and(DB::table('exceptions')->where('kind', 'mass_gap')->value('severity'))->toBe('critical');   // 80 kg gap = 8% > 2 × 2%
    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/shifts/close', $shift)->assertStatus(409);

    $w = $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/waste', ['day' => '2026-11-10', 'stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => 10])->assertCreated();
    expect($w->json('payload.mass_balance.state'))->toBe('missing')
        ->and(DB::table('exceptions')->where('kind', 'mass_gap')->count())->toBe(1);

    // A late entry resolves that machine's not_logged.
    logEntry($this, $this->sup, ['machineId' => $this->loom2, 'shift' => $shift, 'productId' => $this->rihlaat, 'goodUnits' => 76])->assertCreated();
    expect(DB::table('exceptions')->where(['kind' => 'not_logged', 'status' => 'resolved'])->count())->toBe(1);

    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/batches', [
        'day' => '2026-11-10', 'kind' => 'day', 'machineId' => $this->mixer, 'lines' => [['material' => 'PP', 'kg' => 400], ['material' => 'Masterbatch', 'kg' => 12.5]], 'outputKg' => 410,
    ])->assertCreated()->assertJsonPath('payload.input_kg', 412.5);

    $before = productionRows();
    app(Replay::class)->rebuild();
    expect(productionRows())->toEqual($before);
});

it('validates entries and weighings', function (): void {
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    logEntry($this, $this->sup, ['machineId' => (string) Str::uuid7(), 'shift' => $shift])->assertStatus(422);
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-11', 'kind' => 'day']])->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'evening']])->assertStatus(422);
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'stageCode' => 'nope'])->assertStatus(422);
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'goodUnits' => -1])->assertStatus(422);
    $bagsProduct = (string) DB::table('products')->where('site_id', '!=', $this->mats)->value('id');
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'productId' => $bagsProduct])->assertStatus(422);

    $waste = ['day' => '2026-11-10', 'stageCode' => 'weave', 'wasteKind' => 'not_a_kind', 'kg' => 5];
    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/waste', $waste)->assertStatus(422);
    $id = (string) Str::uuid7();
    $ok = [...$waste, 'wasteKind' => $this->waste, 'wasteId' => $id];
    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/waste', $ok)->assertCreated();
    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/waste', $ok)->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');

    $e = logEntry($this, $this->sup, ['entryId' => $id2 = (string) Str::uuid7(), 'machineId' => $this->loom1, 'shift' => $shift])->assertCreated();
    expect($e->json('payload.entry_id'))->toBe($id2);
    logEntry($this, $this->sup, ['entryId' => $id2, 'machineId' => $this->loom2, 'shift' => $shift])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
});

it('keeps production to its own site and roles', function (): void {
    $body = ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'goodUnits' => 1];
    logEntry($this, $this->bagsSup, $body)->assertForbidden();
    logEntry($this, $this->cashier, $body)->assertForbidden();
    $this->withToken($this->bagsSup)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-10&shift=day')->assertForbidden();
    $this->withToken($this->bagsSup)->getJson('/api/v1/sites/mats/mass-balance?day=2026-11-10')->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/mass-balance?day=2026-11-10')->assertForbidden();
    logEntry($this, $this->manager, $body)->assertCreated();
    // A bags machine is not at mats.
    $bagsMachine = (string) DB::table('machines')->where('code_key', 'm_bextA')->value('id');
    logEntry($this, $this->sup, [...$body, 'machineId' => $bagsMachine])->assertStatus(422);
});

it('ingests production events offline and rebuilds exactly', function (): void {
    $id = (string) Str::uuid7();
    $entry = ['id' => $id, 'type' => 'production.entry_recorded', 'occurred_at' => '2026-11-10T09:00:00+03:00',
        'payload' => ['machine_id' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'product_id' => $this->rihlaat, 'good_units' => 5]];
    $bad = ['id' => (string) Str::uuid7(), 'type' => 'production.entry_recorded', 'occurred_at' => '2026-11-10T09:01:00+03:00',
        'payload' => ['machine_id' => (string) Str::uuid7(), 'shift' => ['day' => '2026-11-10', 'kind' => 'day']]];
    $waste = ['id' => (string) Str::uuid7(), 'type' => 'production.waste_weighed', 'occurred_at' => '2026-11-10T09:05:00+03:00',
        'payload' => ['day' => '2026-11-10', 'stage_code' => 'weave', 'waste_kind' => $this->waste, 'kg' => 3.25]];
    $r = $this->withToken($this->sup)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$entry, $entry, $bad, $waste]])->assertOk();

    expect($r->json('results.0.status'))->toBe('accepted')
        ->and($r->json('results.1.status'))->toBe('duplicate')
        ->and($r->json('results.2.status'))->toBe('rejected')
        ->and($r->json('results.3.status'))->toBe('accepted')
        ->and(DB::table('production_entries')->value('event_id'))->toBe($id)
        ->and(DB::table('exceptions')->where('kind', 'below_benchmark')->count())->toBe(1);

    $other = $this->withToken($this->bagsSup)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [[...$entry, 'id' => (string) Str::uuid7()]]]);
    expect($other->status() === 403 || $other->json('results.0.status') === 'rejected')->toBeTrue();

    $before = productionRows();
    app(Replay::class)->rebuild();
    expect(productionRows())->toEqual($before);
});

it('raises not_logged from the scheduler once a shift is six hours in, once per machine and shift', function (): void {
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'productId' => $this->rihlaat, 'goodUnits' => 76])->assertCreated();
    $this->artisan('production:shift-unlogged', ['--at' => '2026-11-10T11:00:00+03:00'])->assertSuccessful();
    expect(DB::table('exceptions')->where('kind', 'not_logged')->count())->toBe(0);
    $this->artisan('production:shift-unlogged', ['--at' => '2026-11-10T13:30:00+03:00'])->assertSuccessful();
    $matsCount = DB::table('exceptions')->where(['kind' => 'not_logged', 'site_id' => $this->mats])->count();
    expect($matsCount)->toBe(14);
    $this->artisan('production:shift-unlogged', ['--at' => '2026-11-10T14:00:00+03:00'])->assertSuccessful();
    expect(DB::table('exceptions')->where(['kind' => 'not_logged', 'site_id' => $this->mats])->count())->toBe(14);
});

it('judges a shift in progress against the benchmark scaled by progress, and a past shift against the full one', function (): void {
    // 10:00 in the day shift: 40 of 76 is not below what is expected so far.
    $now = logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'productId' => $this->rihlaat, 'goodUnits' => 40])->assertCreated();
    expect($now->json('payload.below_benchmark'))->toBeFalse()->and($now->json('payload.progress'))->toBeLessThan(1)
        ->and($now->json('payload.expected_units'))->toBe((int) round(76 * $now->json('payload.progress')));
    $slow = logEntry($this, $this->sup, ['machineId' => $this->loom2, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'productId' => $this->rihlaat, 'goodUnits' => 5])->assertCreated();
    expect($slow->json('payload.below_benchmark'))->toBeTrue();
    $past = logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-09', 'kind' => 'day'], 'productId' => $this->rihlaat, 'goodUnits' => 40])->assertCreated();
    expect($past->json('payload.below_benchmark'))->toBeTrue()->and($past->json('payload.progress'))->toEqual(1);

    $wall = collect($this->withToken($this->ceo)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-10&shift=day')->assertOk()->json('machines'));
    expect($wall->firstWhere('machine.id', $this->loom1)['state'])->toBe('ok')->and($wall->firstWhere('machine.id', $this->loom2)['state'])->toBe('below');
    expect(DB::table('exceptions')->where(['kind' => 'below_benchmark', 'status' => 'open'])->count())->toBe(2);
});

it('caps quantities and validates the day query as a real date', function (): void {
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'goodUnits' => 99_000_000])->assertStatus(422)->assertJsonPath('details.errors.good_units', 'must be at most 10000000');
    logEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'outputKg' => 1e15])->assertStatus(422)->assertJsonPath('details.errors.output_kg', 'must be at most 10000000');
    $this->withToken($this->sup)->postJson('/api/v1/sites/mats/production/waste', ['stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => 1e12])->assertStatus(422)->assertJsonPath('details.errors.kg', 'must be at most 10000000');
    $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/mass-balance?day=2026-02-30')->assertStatus(422)->assertJsonPath('details.errors.day', 'must be a date (YYYY-MM-DD)');
    expect(DB::table('production_entries')->count())->toBe(0);
});
