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
use Tanaad\Shared\Arithmetic\Arithmetic;
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
    $this->bagsMachine = $m('m_bextA');
    $this->rihlaat = (string) DB::table('products')->where('code', 'p_rihlaat')->value('id');
    $this->bagsProduct = (string) DB::table('products')->where('site_id', '!=', $this->mats)->value('id');
    $this->waste = (string) DB::table('ref_items')->where(['list' => 'waste_source', 'site_id' => $this->mats])->value('code');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function edgeEntry(Tests\TestCase $t, string $token, array $body, string $site = 'mats'): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/production/entries", $body);
}

function edgePost(Tests\TestCase $t, string $token, string $path, array $body, string $site = 'mats'): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/production/{$path}", $body);
}

/** @return array<string, mixed> */
function edgeProductionRows(): array
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

it('rejects malformed entry fields with 422 field errors', function (): void {
    $base = ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'day']];
    foreach (['goodUnits', 'rejectUnits', 'downtimeMin'] as $f) {
        foreach ([-1, 1.5, 'abc', 'NaN', [], '1.5'] as $bad) {
            edgeEntry($this, $this->sup, [...$base, $f => $bad])->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
        }
    }
    foreach (['inputKg', 'outputKg'] as $f) {
        foreach ([-0.001, 'abc', 'NaN', [], true] as $bad) {
            edgeEntry($this, $this->sup, [...$base, $f => $bad])->assertStatus(422);
        }
    }
    edgeEntry($this, $this->sup, ['shift' => $base['shift']])->assertStatus(422);
    edgeEntry($this, $this->sup, ['machineId' => 'nope', 'shift' => $base['shift']])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'shift' => ['day' => '2026-11-10']])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'shift' => ['day' => '10-11-2026', 'kind' => 'day']])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'shift' => ['day' => '2026-11-31', 'kind' => 'day']])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'shift' => ['day' => '2026-11-11', 'kind' => 'day']])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'shift' => ['day' => '2027-01-01', 'kind' => 'night']])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'labelCodes' => 'x'])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'labelCodes' => [1]])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'labelCodes' => ['']])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'downtimeReason' => str_repeat('x', 256)])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'entryId' => 'nope'])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'operatorId' => 'nope'])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'productId' => (string) Str::uuid7()])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'productId' => $this->bagsProduct])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'machineId' => $this->bagsMachine])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'machineId' => (string) Str::uuid7()])->assertStatus(422);
    edgeEntry($this, $this->sup, [...$base, 'stageCode' => 'nope'])->assertStatus(422);
    $err = edgeEntry($this, $this->sup, ['goodUnits' => -1, 'machineId' => 'x'])->assertStatus(422)->json();
    expect(json_encode($err))->toContain('good_units')->toContain('machine_id')->toContain('day');
    expect(DB::table('production_entries')->count())->toBe(0);
});

it('accepts boundary entry values: zero, decimal strings for kg, today and past days', function (): void {
    $base = ['machineId' => $this->loom1];
    edgeEntry($this, $this->sup, [...$base, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'goodUnits' => 0, 'inputKg' => '0.0005', 'outputKg' => '12.3456'])->assertCreated();
    expect((float) DB::table('production_entries')->value('output_kg'))->toBe(12.346)->and((float) DB::table('production_entries')->value('input_kg'))->toBe(0.001);
    edgeEntry($this, $this->sup, [...$base, 'shift' => ['day' => '2020-01-01', 'kind' => 'night'], 'goodUnits' => '7'])->assertCreated();
    edgeEntry($this, $this->sup, [...$base, 'day' => '2026-11-09', 'kind' => 'day', 'goodUnits' => 1])->assertCreated();
});

it('rejects over-wide entry values with 422, not 500', function (): void {
    edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'outputKg' => 1e12])->assertStatus(422);
});

it('rejects the numeric string 1e999 with 422, not 500', function (): void {
    edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'day'], 'outputKg' => '1e999'])->assertStatus(422);
});

it('flags below_benchmark exactly at the configured pct threshold', function (): void {
    DB::table('site_settings')->where('site_id', $this->mats)->update(['benchmark_pct' => 75]);   // 76 x 75% = 57 exactly (a past shift counts in full)
    $day = ['day' => '2026-11-09', 'kind' => 'day'];
    $at = edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => 57])->assertCreated();
    expect($at->json('payload.below_benchmark'))->toBeFalse()->and($at->json('payload.benchmark'))->toEqual(76)->and($at->json('payload.benchmark_pct'))->toEqual(75);
    $under = edgeEntry($this, $this->sup, ['machineId' => $this->loom2, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => 56])->assertCreated();
    expect($under->json('payload.below_benchmark'))->toBeTrue();
    expect(DB::table('exceptions')->where('kind', 'below_benchmark')->where('status', 'open')->count())->toBe(1);

    // Re-log of the under entry at exactly the threshold resolves its exception.
    edgeEntry($this, $this->sup, ['machineId' => $this->loom2, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => 57])->assertCreated();
    expect(DB::table('exceptions')->where('kind', 'below_benchmark')->where('status', 'open')->count())->toBe(0);

    // No product, or a machine/product pair without a benchmark, is never below.
    $none = edgeEntry($this, $this->sup, ['machineId' => $this->mixer, 'shift' => $day, 'goodUnits' => 0])->assertCreated();
    expect($none->json('payload.below_benchmark'))->toBeFalse()->and($none->json('payload.benchmark'))->toBeNull();
    $zero = edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-08', 'kind' => 'day'], 'productId' => $this->rihlaat, 'goodUnits' => 0])->assertCreated();
    expect($zero->json('payload.below_benchmark'))->toBeTrue();

    DB::table('site_settings')->where('site_id', $this->mats)->update(['benchmark_pct' => 100]);
    $full = edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-07', 'kind' => 'day'], 'productId' => $this->rihlaat, 'goodUnits' => 76])->assertCreated();
    $short = edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => ['day' => '2026-11-06', 'kind' => 'day'], 'productId' => $this->rihlaat, 'goodUnits' => 75])->assertCreated();
    expect($full->json('payload.below_benchmark'))->toBeFalse()->and($short->json('payload.below_benchmark'))->toBeTrue();
});

it('conflicts on a reused entry id for another machine or shift, and keeps the id on a re-log', function (): void {
    $day = ['day' => '2026-11-10', 'kind' => 'day'];
    $id = (string) Str::uuid7();
    edgeEntry($this, $this->sup, ['entryId' => $id, 'machineId' => $this->loom1, 'shift' => $day, 'goodUnits' => 1])->assertCreated();
    edgeEntry($this, $this->sup, ['entryId' => $id, 'machineId' => $this->loom2, 'shift' => $day])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    edgeEntry($this, $this->sup, ['entryId' => $id, 'machineId' => $this->loom1, 'shift' => ['day' => '2026-11-10', 'kind' => 'night']])->assertStatus(409);
    $relog = edgeEntry($this, $this->sup, ['entryId' => (string) Str::uuid7(), 'machineId' => $this->loom1, 'shift' => $day, 'goodUnits' => 9])->assertCreated();
    expect($relog->json('payload.entry_id'))->toBe($id)->and($relog->json('payload.replaces'))->toBeTrue()
        ->and(DB::table('production_entries')->count())->toBe(1)->and((int) DB::table('production_entries')->value('good_units'))->toBe(9);
});

it('validates waste weighings and conflicts on a reused id', function (): void {
    $ok = ['day' => '2026-11-10', 'stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => 5];
    foreach ([0, -1, 'abc', 'NaN', [], null, 0.0004] as $bad) {
        $r = edgePost($this, $this->sup, 'waste', [...$ok, 'kg' => $bad]);
        // 0.0004 rounds to 0.000 kg: a weighing of nothing must not be stored.
        if ($bad === 0.0004) {
            expect(in_array($r->status(), [201, 422], true))->toBeTrue();

            continue;
        }
        $r->assertStatus(422);
    }
    edgePost($this, $this->sup, 'waste', [...$ok, 'day' => '2026-11-11'])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'day' => 'x'])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'stageCode' => 'nope'])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'stageCode' => ''])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'wasteKind' => ''])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'wasteKind' => 'nope'])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'machineId' => $this->bagsMachine])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'machineId' => (string) Str::uuid7()])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'reason' => str_repeat('x', 501)])->assertStatus(422);
    edgePost($this, $this->sup, 'waste', [...$ok, 'wasteId' => 'nope'])->assertStatus(422);
    $before = DB::table('waste_weighings')->count();
    $id = (string) Str::uuid7();
    edgePost($this, $this->sup, 'waste', [...$ok, 'wasteId' => $id, 'kg' => '2.5'])->assertCreated();
    edgePost($this, $this->sup, 'waste', [...$ok, 'wasteId' => $id])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    expect(DB::table('waste_weighings')->count())->toBe($before + 1);
});

it('validates batches and conflicts on a reused batch id', function (): void {
    $ok = ['day' => '2026-11-10', 'kind' => 'day', 'machineId' => $this->mixer, 'lines' => [['material' => 'PP', 'kg' => 100]], 'outputKg' => 99];
    foreach ([
        ['lines' => []], ['lines' => 'x'], ['lines' => [['material' => 'PP', 'kg' => 0]]], ['lines' => [['material' => 'PP', 'kg' => -1]]],
        ['lines' => [['material' => 'PP', 'kg' => 'abc']]], ['lines' => [['material' => '', 'kg' => 5]]], ['lines' => [['kg' => 5]]],
        ['lines' => ['x']], ['lines' => [['material' => 'PP']]],
        ['outputKg' => -1], ['outputKg' => 'abc'], ['day' => '2026-11-11'], ['kind' => 'evening'], ['batchId' => 'nope'],
        ['machineId' => $this->bagsMachine], ['machineId' => (string) Str::uuid7()], ['productId' => $this->bagsProduct], ['productId' => (string) Str::uuid7()],
        ['note' => str_repeat('x', 1001)], ['labelCode' => str_repeat('x', 65)],
    ] as $over) {
        edgePost($this, $this->sup, 'batches', [...$ok, ...$over])->assertStatus(422);
    }
    $noLines = $ok;
    unset($noLines['lines']);
    edgePost($this, $this->sup, 'batches', $noLines)->assertStatus(422);
    $noOut = $ok;
    unset($noOut['outputKg']);
    edgePost($this, $this->sup, 'batches', $noOut)->assertStatus(422);
    expect(DB::table('production_batches')->count())->toBe(0);

    $id = (string) Str::uuid7();
    $r = edgePost($this, $this->sup, 'batches', [...$ok, 'batchId' => $id, 'kind' => null, 'lines' => [['material' => 'PP', 'kg' => '0.1'], ['material' => 'PP', 'kg' => '0.2']]]);
    expect($r->status())->toBeIn([201, 422]);
    $r2 = edgePost($this, $this->sup, 'batches', [...$ok, 'batchId' => $id, 'lines' => [['material' => 'PP', 'kg' => '0.1'], ['material' => 'PP', 'kg' => '0.2']]])->assertCreated();
    expect($r2->json('payload.input_kg'))->toEqual(0.3);
    edgePost($this, $this->sup, 'batches', [...$ok, 'batchId' => $id])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
});

it('closes a shift once; another shift of the same day stays closeable; bad input is 422', function (): void {
    $day = ['day' => '2026-11-10', 'kind' => 'day'];
    edgePost($this, $this->sup, 'shifts/close', ['day' => '2026-11-11', 'kind' => 'day'])->assertStatus(422);
    edgePost($this, $this->sup, 'shifts/close', ['day' => '2026-11-10', 'kind' => 'evening'])->assertStatus(422);
    edgePost($this, $this->sup, 'shifts/close', ['day' => '2026-11-10'])->assertStatus(422);
    edgePost($this, $this->sup, 'shifts/close', [])->assertStatus(422);
    edgePost($this, $this->sup, 'shifts/close', $day)->assertCreated();
    edgePost($this, $this->manager, 'shifts/close', $day)->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    edgePost($this, $this->sup, 'shifts/close', ['shift' => $day])->assertStatus(409);
    edgePost($this, $this->sup, 'shifts/close', ['day' => '2026-11-10', 'kind' => 'night'])->assertCreated();
    expect(DB::table('production_shifts_closed')->count())->toBe(2);
    // Mass gap exception raised at most once per day even after two closes.
    expect(DB::table('exceptions')->where('kind', 'mass_gap')->count())->toBeLessThanOrEqual(1);
});

it('computes the mass balance states balanced, explainable and missing from the arithmetic fixtures', function (): void {
    $fixtures = collect(json_decode((string) file_get_contents(base_path('../../contracts/fixtures/arithmetic.json')), true)['massBalance'])->keyBy('name');
    foreach (['balanced', 'explainable', 'missing', 'nothing in', 'negative wip'] as $name) {
        expect(Arithmetic::massBalance($fixtures[$name]['input'][0]))->toEqual($fixtures[$name]['expected']);
    }
    // allowed 2%: in 1000 -> 20 kg allowed. out + waste + 20 against 1000.
    $log = function (string $day, float $out, float $waste = 0.0) {
        $shift = ['day' => $day, 'kind' => 'day'];
        edgeEntry($this, $this->sup, ['machineId' => $this->mixer, 'shift' => $shift, 'inputKg' => 1000, 'outputKg' => 1000])->assertCreated();
        edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $shift, 'outputKg' => $out])->assertCreated();
        if ($waste > 0) {
            edgePost($this, $this->sup, 'waste', ['day' => $day, 'stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => $waste])->assertCreated();
        }

        return $this->withToken($this->manager)->getJson("/api/v1/sites/mats/mass-balance?day={$day}")->assertOk()->json();
    };
    $cases = [
        ['2026-11-01', 1000, 0, 'balanced', -2, -20],
        ['2026-11-02', 981, 0, 'balanced', -0.1, -1],
        ['2026-11-03', 980, 0, 'balanced', 0, 0],
        ['2026-11-04', 979, 0, 'explainable', 0.1, 1],
        ['2026-11-05', 960, 0, 'explainable', 2, 20],       // exactly at the allowed pct
        ['2026-11-06', 959, 0, 'missing', 2.1, 21],         // one kg past it
        ['2026-11-07', 900, 80, 'balanced', 0, 0],          // waste explains the loss
        ['2026-11-08', 900, 60, 'explainable', 2, 20],
    ];
    foreach ($cases as [$day, $out, $waste, $state, $pct, $gap]) {
        $mb = $log($day, (float) $out, (float) $waste);
        expect($mb)->toMatchArray(['kgIn' => 1000, 'kgOut' => $out, 'wasteKg' => $waste, 'allowedLossKg' => 20, 'state' => $state])
            ->and($mb['gapKg'])->toEqual($gap)->and($mb['gapPct'])->toEqual($pct);
    }
    $empty = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/mass-balance?day=2026-10-01')->assertOk();
    expect($empty->json())->toMatchArray(['kgIn' => 0, 'kgOut' => 0, 'gapKg' => 0, 'gapPct' => 0, 'state' => 'balanced']);
});

it('validates read query parameters', function (): void {
    foreach (['mass-balance', 'production/output', 'machine-wall'] as $path) {
        $this->withToken($this->sup)->getJson("/api/v1/sites/mats/{$path}?day=nope")->assertStatus(422);
        $this->withToken($this->sup)->getJson("/api/v1/sites/mats/{$path}?day=2026-1-1")->assertStatus(422);
        $this->withToken($this->sup)->getJson("/api/v1/sites/mats/{$path}")->assertOk();
    }
    $this->withToken($this->sup)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-10&shift=evening')->assertStatus(422);
    $this->withToken($this->sup)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-10&shift[]=day')->assertStatus(422);
    $this->withToken($this->sup)->getJson('/api/v1/sites/mats/machine-wall?day=2026-11-10&shift=day&stage=nope')->assertOk();
});

it('enforces role and site on every production endpoint', function (): void {
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    $writes = [
        'entries' => ['machineId' => $this->loom1, 'shift' => $shift, 'goodUnits' => 1],
        'batches' => ['day' => '2026-11-10', 'machineId' => $this->mixer, 'lines' => [['material' => 'PP', 'kg' => 1]], 'outputKg' => 1],
        'shifts/close' => $shift,
        'waste' => ['day' => '2026-11-10', 'stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => 1],
    ];
    foreach ($writes as $path => $body) {
        foreach ([$this->bagsSup, $this->cashier, $this->ceo] as $t) {
            edgePost($this, $t, $path, $body)->assertForbidden();
        }
        // A mats supervisor aimed at the bags site.
        edgePost($this, $this->sup, $path, $body, 'bags')->assertForbidden();
        $this->flushHeaders()->postJson("/api/v1/sites/mats/production/{$path}", $body)->assertUnauthorized();
    }
    foreach (['machine-wall?day=2026-11-10&shift=day', 'mass-balance?day=2026-11-10', 'production/output?day=2026-11-10'] as $path) {
        $this->withToken($this->bagsSup)->getJson("/api/v1/sites/mats/{$path}")->assertForbidden();
        $this->withToken($this->cashier)->getJson("/api/v1/sites/mats/{$path}")->assertForbidden();
        $this->withToken($this->sup)->getJson("/api/v1/sites/bags/{$path}")->assertForbidden();
        $this->withToken($this->ceo)->getJson("/api/v1/sites/mats/{$path}")->assertOk();
        $this->withToken($this->manager)->getJson("/api/v1/sites/mats/{$path}")->assertOk();
    }
    foreach (['production_entries', 'production_batches', 'production_shifts_closed', 'waste_weighings'] as $snap) {
        $this->withToken($this->bagsSup)->getJson("/api/v1/sites/mats/snapshots/{$snap}?since=0")->assertForbidden();
        $this->withToken($this->ceo)->getJson("/api/v1/sites/mats/snapshots/{$snap}?since=0")->assertOk();
    }
    foreach (['production_entries', 'production_batches', 'production_shifts_closed', 'waste_weighings'] as $snap) {
        $this->withToken($this->cashier)->getJson("/api/v1/sites/mats/snapshots/{$snap}?since=0")->assertForbidden();
    }
    expect(DB::table('production_entries')->count() + DB::table('production_batches')->count() + DB::table('waste_weighings')->count() + DB::table('production_shifts_closed')->count())->toBe(0);
});

it('enforces role and site on writes and REST reads (snapshots excluded)', function (): void {
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    $writes = [
        'entries' => ['machineId' => $this->loom1, 'shift' => $shift, 'goodUnits' => 1],
        'batches' => ['day' => '2026-11-10', 'machineId' => $this->mixer, 'lines' => [['material' => 'PP', 'kg' => 1]], 'outputKg' => 1],
        'shifts/close' => $shift,
        'waste' => ['day' => '2026-11-10', 'stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => 1],
    ];
    foreach ($writes as $path => $body) {
        foreach ([$this->bagsSup, $this->cashier, $this->ceo] as $t) {
            edgePost($this, $t, $path, $body)->assertForbidden();
        }
        edgePost($this, $this->sup, $path, $body, 'bags')->assertForbidden();
        $this->flushHeaders()->postJson("/api/v1/sites/mats/production/{$path}", $body)->assertUnauthorized();
    }
    foreach (['machine-wall?day=2026-11-10&shift=day', 'mass-balance?day=2026-11-10', 'production/output?day=2026-11-10'] as $path) {
        $this->withToken($this->bagsSup)->getJson("/api/v1/sites/mats/{$path}")->assertForbidden();
        $this->withToken($this->cashier)->getJson("/api/v1/sites/mats/{$path}")->assertForbidden();
        $this->withToken($this->sup)->getJson("/api/v1/sites/bags/{$path}")->assertForbidden();
        $this->withToken($this->ceo)->getJson("/api/v1/sites/mats/{$path}")->assertOk();
        $this->withToken($this->manager)->getJson("/api/v1/sites/mats/{$path}")->assertOk();
    }
    foreach (['production_entries', 'production_batches', 'production_shifts_closed', 'waste_weighings'] as $snap) {
        $this->withToken($this->bagsSup)->getJson("/api/v1/sites/mats/snapshots/{$snap}?since=0")->assertForbidden();
        $this->withToken($this->ceo)->getJson("/api/v1/sites/mats/snapshots/{$snap}?since=0")->assertOk();
    }
    expect(DB::table('production_entries')->count() + DB::table('production_batches')->count() + DB::table('waste_weighings')->count() + DB::table('production_shifts_closed')->count())->toBe(0);
});

it('handles an offline batch mixing valid, invalid, duplicate and conflicting production events', function (): void {
    $ev = fn (string $type, array $payload, ?string $id = null, string $at = '2026-11-10T09:00:00+03:00') => ['id' => $id ?? (string) Str::uuid7(), 'type' => $type, 'occurred_at' => $at, 'payload' => $payload];
    $shift = ['day' => '2026-11-10', 'kind' => 'day'];
    $entryId = (string) Str::uuid7();
    $entry = $ev('production.entry_recorded', ['machine_id' => $this->loom1, 'shift' => $shift, 'product_id' => $this->rihlaat, 'good_units' => 20, 'entry_id' => $entryId]);
    $sameFact = $ev('production.entry_recorded', ['machine_id' => $this->loom2, 'shift' => $shift, 'entry_id' => $entryId]);
    $future = $ev('production.entry_recorded', ['machine_id' => $this->loom2, 'shift' => ['day' => '2026-11-12', 'kind' => 'day']]);
    $negative = $ev('production.entry_recorded', ['machine_id' => $this->loom2, 'shift' => $shift, 'good_units' => -5]);
    $badWaste = $ev('production.waste_weighed', ['day' => '2026-11-10', 'stage_code' => 'weave', 'waste_kind' => $this->waste, 'kg' => 0]);
    $goodWaste = $ev('production.waste_weighed', ['day' => '2026-11-10', 'stage_code' => 'weave', 'waste_kind' => $this->waste, 'kg' => 4.5]);
    $close = $ev('production.shift_closed', $shift);
    $closeAgain = $ev('production.shift_closed', $shift);
    $batch = $ev('production.batch_recorded', ['day' => '2026-11-10', 'lines' => [['material' => 'PP', 'kg' => 5]], 'output_kg' => 4]);
    $badBatch = $ev('production.batch_recorded', ['day' => '2026-11-10', 'lines' => [], 'output_kg' => 4]);
    $unknown = $ev('production.exploded', []);
    $r = $this->withToken($this->sup)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(),
        'events' => [$entry, $entry, $sameFact, $future, $negative, $badWaste, $goodWaste, $close, $closeAgain, $batch, $badBatch, $unknown]])->assertOk();
    $status = array_column($r->json('results'), 'status');
    expect($status)->toBe(['accepted', 'duplicate', 'rejected', 'rejected', 'rejected', 'rejected', 'accepted', 'accepted', 'rejected', 'accepted', 'rejected', 'rejected'])
        ->and($r->json('results.2.code'))->toBe('duplicate_fact')->and($r->json('results.3.code'))->toBe('invalid_payload')
        ->and($r->json('results.8.code'))->toBe('state_conflict');
    expect(DB::table('production_entries')->count())->toBe(1)->and(DB::table('waste_weighings')->count())->toBe(1)
        ->and(DB::table('production_batches')->count())->toBe(1)->and(DB::table('production_shifts_closed')->count())->toBe(1);

    $before = edgeProductionRows();
    app(Replay::class)->rebuild();
    expect(edgeProductionRows())->toEqual($before);
});

it('rebuilds exactly after a run of rejected commands, re-logs and conflicts', function (): void {
    $day = ['day' => '2026-11-10', 'kind' => 'day'];
    edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => 5, 'inputKg' => 3, 'outputKg' => 2.0004])->assertCreated();
    edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => -5])->assertStatus(422);
    edgeEntry($this, $this->sup, ['machineId' => $this->loom1, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => 80, 'outputKg' => 700])->assertCreated();
    edgeEntry($this, $this->sup, ['machineId' => $this->mixer, 'shift' => $day, 'inputKg' => 1000])->assertCreated();
    edgePost($this, $this->sup, 'waste', ['day' => '2026-11-10', 'stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => 1.2345])->assertCreated();
    edgePost($this, $this->sup, 'waste', ['day' => '2026-11-10', 'stageCode' => 'weave', 'wasteKind' => $this->waste, 'kg' => 0])->assertStatus(422);
    edgePost($this, $this->sup, 'batches', ['day' => '2026-11-10', 'machineId' => $this->mixer, 'lines' => [['material' => 'PP', 'kg' => 5.5555]], 'outputKg' => 5])->assertCreated();
    edgePost($this, $this->sup, 'shifts/close', $day)->assertCreated();
    edgePost($this, $this->sup, 'shifts/close', $day)->assertStatus(409);
    edgeEntry($this, $this->sup, ['machineId' => $this->loom2, 'shift' => $day, 'productId' => $this->rihlaat, 'goodUnits' => 76])->assertCreated();

    $before = edgeProductionRows();
    $book = app(Tanaad\Production\Support\ProductionBook::class);
    $mb = $book->massBalance($this->mats, '2026-11-10');
    app(Replay::class)->rebuild();
    expect(edgeProductionRows())->toEqual($before)->and($book->massBalance($this->mats, '2026-11-10'))->toEqual($mb);
    app(Replay::class)->rebuild();
    expect(edgeProductionRows())->toEqual($before);
});
