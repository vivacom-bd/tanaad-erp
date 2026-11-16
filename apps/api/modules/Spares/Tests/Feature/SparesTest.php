<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00:00', 'Africa/Kampala'));
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->manager = $token('u_sm_mats');
    $this->store = $token('u_store_mats');
    $this->sup = $token('u_sup_mats');
    $this->mech = $token('u_mech');
    $this->cashier = $token('u_cash_mats');
    $this->bagsManager = $token('u_sm_bags');
    $this->admin = $token('u_admin');
    $this->ceo = $token('u_ceo1');
    $this->loom1 = (string) DB::table('machines')->where('code_key', 'm_loom1')->value('id');
    $this->loom2 = (string) DB::table('machines')->where('code_key', 'm_loom2')->value('id');
    $this->bagsMachine = (string) DB::table('machines')->where('code_key', 'm_conv1')->value('id');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function sparePart(Tests\TestCase $t, array $body = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    return $t->withToken($token ?? $t->manager)->postJson('/api/v1/sites/mats/parts', [
        'code' => 'BRG-6204', 'name' => 'Bearing 6204', 'unit' => 'pcs', 'minLevel' => 5, 'unitCost' => 12000, 'machineKinds' => ['loom'], ...$body,
    ]);
}

function receiveParts(Tests\TestCase $t, string $part, array $body = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    return $t->withToken($token ?? $t->store)->postJson("/api/v1/sites/mats/parts/{$part}/receive", ['qty' => 10, 'supplier' => 'Kampala Bearings', ...$body]);
}

function issueParts(Tests\TestCase $t, string $part, array $body = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    return $t->withToken($token ?? $t->store)->postJson("/api/v1/sites/mats/parts/{$part}/issue", ['qty' => 2, 'machineId' => $t->loom1, ...$body]);
}

function stocked(Tests\TestCase $t, int $qty = 10): string
{
    $id = sparePart($t)->assertCreated()->json('part.id');
    receiveParts($t, $id, ['qty' => $qty])->assertCreated();

    return $id;
}

/** @return array<string, mixed> */
function sparesRows(): array
{
    $strip = fn ($rows) => $rows->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();

    return [
        'parts' => $strip(DB::table('parts')->orderBy('id')->get()),
        'receipts' => $strip(DB::table('part_receipts')->orderBy('id')->get()),
        'issues' => $strip(DB::table('part_issues')->orderBy('id')->get()),
        'breakdowns' => $strip(DB::table('breakdowns')->orderBy('id')->get()),
        'exceptions' => $strip(DB::table('exceptions')->orderBy('id')->get()),
    ];
}

it('keeps the parts catalogue as master data: manager or group admin only, unique code per site, validated', function (): void {
    $a = sparePart($this)->assertCreated();
    expect($a->json('part'))->toMatchArray(['code' => 'BRG-6204', 'unit' => 'pcs', 'minLevel' => 5, 'unitCost' => 12000, 'onHand' => 0, 'machineKinds' => ['loom']]);
    sparePart($this, [], $this->store)->assertForbidden();
    sparePart($this, [], $this->bagsManager)->assertForbidden();
    sparePart($this, [], $this->ceo)->assertForbidden();
    sparePart($this, ['name' => 'Other'])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    sparePart($this, ['code' => 'BRG-6204'], $this->admin)->assertStatus(409);
    // the same code at another site is fine
    $this->withToken($this->bagsManager)->postJson('/api/v1/sites/bags/parts', ['code' => 'BRG-6204', 'name' => 'Bearing', 'unit' => 'pcs', 'minLevel' => 1, 'unitCost' => 1])->assertCreated();
    // edit keeps stock
    $id = $a->json('part.id');
    receiveParts($this, $id)->assertCreated();
    expect(sparePart($this, ['id' => $id, 'name' => 'Bearing 6204 ZZ', 'unitCost' => 13000])->assertCreated()->json('part'))->toMatchArray(['onHand' => 10, 'name' => 'Bearing 6204 ZZ']);
    // another site's part id is not found
    $this->withToken($this->bagsManager)->postJson('/api/v1/sites/bags/parts', ['id' => $id, 'code' => 'X', 'name' => 'x', 'unit' => 'pcs', 'minLevel' => 1, 'unitCost' => 1])->assertNotFound();
    foreach ([['code' => ''], ['name' => ''], ['unit' => ''], ['minLevel' => -1], ['minLevel' => 'abc'], ['minLevel' => 1e7], ['minLevel' => 1.2345], ['unitCost' => -5], ['unitCost' => 1e9],
        ['machineKinds' => 'loom'], ['machineKinds' => [1]], ['id' => 'nope'], ['code' => str_repeat('x', 33)]] as $bad) {
        sparePart($this, $bad + ['code' => 'ZZ'])->assertStatus(422);
    }
});

it('receives stock, averages the cost, and issues at the average; more than on hand is 409', function (): void {
    $id = stocked($this);                                    // 10 @ 12000
    $r = receiveParts($this, $id, ['qty' => 10, 'unitCost' => 14000])->assertCreated()->json('part');
    expect($r['onHand'])->toEqual(20)->and($r['unitCost'])->toEqual(13000);
    $i = issueParts($this, $id, ['qty' => 4, 'reason' => 'Worn'])->assertCreated()->json('issue');
    expect($i)->toMatchArray(['qty' => 4, 'unitCost' => 13000, 'cost' => 52000, 'machineId' => $this->loom1, 'reason' => 'Worn']);
    expect((float) DB::table('parts')->where('id', $id)->value('on_hand'))->toEqual(16.0);
    issueParts($this, $id, ['qty' => 17])->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    issueParts($this, $id, ['qty' => 16])->assertCreated();
    issueParts($this, $id, ['qty' => 1])->assertStatus(409);
    expect((float) DB::table('parts')->where('id', $id)->value('on_hand'))->toEqual(0.0);
});

it('rejects bad receipts and issues, duplicates, and machines of other sites', function (): void {
    $id = stocked($this);
    foreach ([0, -1, 'abc', 1e6 + 1, 1.2345, null, [], true] as $bad) {
        receiveParts($this, $id, ['qty' => $bad])->assertStatus(422);
        issueParts($this, $id, ['qty' => $bad])->assertStatus(422);
    }
    receiveParts($this, $id, ['unitCost' => -1])->assertStatus(422);
    receiveParts($this, $id, ['receiptId' => 'nope'])->assertStatus(422);
    $rid = (string) Str::uuid7();
    receiveParts($this, $id, ['receiptId' => $rid])->assertCreated();
    receiveParts($this, $id, ['receiptId' => $rid])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    $iid = (string) Str::uuid7();
    issueParts($this, $id, ['issueId' => $iid])->assertCreated();
    issueParts($this, $id, ['issueId' => $iid])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    expect((float) DB::table('parts')->where('id', $id)->value('on_hand'))->toEqual(18.0);   // 10 + 10 - 2, the duplicates moved nothing
    issueParts($this, $id, ['machineId' => $this->bagsMachine])->assertStatus(422);
    issueParts($this, $id, ['machineId' => (string) Str::uuid7()])->assertStatus(422);
    issueParts($this, $id, ['machineId' => null])->assertStatus(422);
    issueParts($this, $id, ['mechanicId' => (string) Str::uuid7()])->assertStatus(422);
    issueParts($this, $id, ['breakdownId' => (string) Str::uuid7()])->assertStatus(422);
    issueParts($this, (string) Str::uuid7())->assertNotFound();
});

it('limits who receives and who issues, and who reads', function (): void {
    $id = stocked($this);
    issueParts($this, $id, [], $this->sup)->assertCreated();
    issueParts($this, $id, [], $this->mech)->assertForbidden();
    issueParts($this, $id, [], $this->manager)->assertForbidden();
    issueParts($this, $id, [], $this->ceo)->assertForbidden();
    receiveParts($this, $id, [], $this->sup)->assertForbidden();
    receiveParts($this, $id, [], $this->manager)->assertCreated();
    foreach (['spares'] as $path) {
        foreach ([$this->mech, $this->store, $this->manager, $this->ceo, $this->admin] as $t) {
            $this->withToken($t)->getJson("/api/v1/sites/mats/{$path}")->assertOk();
        }
        foreach ([$this->cashier, $this->sup, $this->bagsManager] as $t) {
            $this->withToken($t)->getJson("/api/v1/sites/mats/{$path}")->assertForbidden();
        }
    }
    $body = $this->withToken($this->mech)->getJson('/api/v1/sites/mats/spares')->assertOk()->json();
    expect($body['parts'])->toHaveCount(1)->and($body['issues'])->toHaveCount(1);
    $this->withToken($this->bagsManager)->getJson('/api/v1/sites/bags/spares')->assertOk()->assertJsonPath('parts', []);
});

it('raises low_stock when an issue leaves a part under its minimum, once, and settles it on receipt', function (): void {
    $id = stocked($this, 6);                                 // min 5
    issueParts($this, $id, ['qty' => 1])->assertCreated();   // 5: at the minimum, fine
    expect(DB::table('exceptions')->where('kind', 'low_stock')->count())->toBe(0);
    issueParts($this, $id, ['qty' => 1])->assertCreated();   // 4: below
    issueParts($this, $id, ['qty' => 1])->assertCreated();   // 3: still the same exception
    $ex = DB::table('exceptions')->where('kind', 'low_stock')->get();
    expect($ex)->toHaveCount(1)->and($ex[0]->subject_ref)->toBe($id)->and($ex[0]->owner_role)->toBe('storekeeper')->and($ex[0]->status)->toBe('open')
        ->and($ex[0]->title)->toBe('Bearing 6204 below minimum (4 of 5)');
    receiveParts($this, $id, ['qty' => 1])->assertCreated();   // 4: still below
    expect(DB::table('exceptions')->where('kind', 'low_stock')->value('status'))->toBe('open');
    receiveParts($this, $id, ['qty' => 1])->assertCreated();   // 5: back at the minimum
    expect(DB::table('exceptions')->where('kind', 'low_stock')->value('status'))->toBe('resolved');
    issueParts($this, $id, ['qty' => 1])->assertCreated();     // 4: a new one
    expect(DB::table('exceptions')->where('kind', 'low_stock')->where('status', 'open')->count())->toBe(1);
    sparePart($this, ['id' => $id, 'minLevel' => 2])->assertCreated();   // lowering the minimum under the stock settles it
    expect(DB::table('exceptions')->where('kind', 'low_stock')->where('status', 'open')->count())->toBe(0);
});

it('reports a breakdown, links issues to it, and resolves it with minutes and spares cost', function (): void {
    $id = stocked($this);
    $b = $this->withToken($this->mech)->postJson('/api/v1/sites/mats/breakdowns', ['machineId' => $this->loom1, 'cause' => 'Bearing seized', 'startedAt' => '2026-11-10T05:00:00Z'])->assertCreated()->json('breakdown');
    expect($b)->toMatchArray(['open' => true, 'cause' => 'Bearing seized', 'sparesCost' => 0]);
    $down = $this->withToken($this->mech)->getJson('/api/v1/sites/mats/breakdowns')->assertOk()->json('machinesDown');
    expect($down)->toHaveCount(1)->and($down[0]['machineId'])->toBe($this->loom1);
    $this->withToken($this->mech)->postJson('/api/v1/sites/mats/breakdowns', ['machineId' => $this->loom1, 'cause' => 'again'])->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    issueParts($this, $id, ['qty' => 2, 'breakdownId' => $b['id']])->assertCreated();
    issueParts($this, $id, ['qty' => 1, 'breakdownId' => $b['id'], 'machineId' => $this->loom2])->assertStatus(422);
    issueParts($this, $id, ['qty' => 1])->assertCreated();   // not linked
    $r = $this->withToken($this->sup)->postJson("/api/v1/sites/mats/breakdowns/{$b['id']}/resolve")->assertCreated()->json('breakdown');
    expect($r)->toMatchArray(['open' => false, 'durationMinutes' => 120, 'sparesCost' => 24000]);
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/breakdowns/{$b['id']}/resolve")->assertStatus(409);
    issueParts($this, $id, ['qty' => 1, 'breakdownId' => $b['id']])->assertStatus(409);
    expect($this->withToken($this->mech)->getJson('/api/v1/sites/mats/breakdowns')->json('machinesDown'))->toBe([]);
    $this->withToken($this->mech)->postJson('/api/v1/sites/mats/breakdowns', ['machineId' => $this->loom1, 'cause' => 'next'])->assertCreated();   // free again
});

it('validates breakdowns and limits who reports and reads them', function (): void {
    $report = fn (array $b, ?string $t = null) => $this->withToken($t ?? $this->mech)->postJson('/api/v1/sites/mats/breakdowns', ['machineId' => $this->loom1, 'cause' => 'x', ...$b]);
    foreach ([['cause' => ''], ['cause' => str_repeat('x', 501)], ['machineId' => 'nope'], ['machineId' => $this->bagsMachine], ['machineId' => (string) Str::uuid7()],
        ['startedAt' => '2026-11-11T05:00:00Z'], ['startedAt' => 'yesterday-ish'], ['startedAt' => []], ['id' => 'nope']] as $bad) {
        $report($bad)->assertStatus(422);
    }
    $report([], $this->store)->assertForbidden();
    $report([], $this->cashier)->assertForbidden();
    $report([], $this->bagsManager)->assertForbidden();
    $report([], $this->ceo)->assertForbidden();
    $id = $report(['id' => (string) Str::uuid7()], $this->sup)->assertCreated()->json('breakdown.id');
    $report(['id' => $id, 'machineId' => $this->loom2])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    $this->withToken($this->mech)->postJson("/api/v1/sites/mats/breakdowns/{$id}/resolve", ['resolvedAt' => '2020-01-01T00:00:00Z'])->assertStatus(422);
    $this->withToken($this->mech)->postJson("/api/v1/sites/mats/breakdowns/{$id}/resolve", ['resolvedAt' => '2026-11-12T00:00:00Z'])->assertStatus(422);
    $this->withToken($this->store)->postJson("/api/v1/sites/mats/breakdowns/{$id}/resolve")->assertForbidden();
    $this->withToken($this->bagsManager)->postJson("/api/v1/sites/mats/breakdowns/{$id}/resolve")->assertForbidden();
    $this->withToken($this->mech)->postJson('/api/v1/sites/mats/breakdowns/'.Str::uuid7().'/resolve')->assertNotFound();
    foreach (['breakdowns', 'downtime-cost'] as $path) {
        foreach ([$this->mech, $this->sup, $this->manager, $this->ceo, $this->admin] as $t) {
            $this->withToken($t)->getJson("/api/v1/sites/mats/{$path}")->assertOk();
        }
        foreach ([$this->store, $this->cashier, $this->bagsManager] as $t) {
            $this->withToken($t)->getJson("/api/v1/sites/mats/{$path}")->assertForbidden();
        }
    }
});

it('answers the downtime cost per machine and period, clipped to the period', function (): void {
    $id = stocked($this);
    // loom1: down 2026-11-09 19:00Z → 2026-11-10 02:00Z (7 h; the Kampala day of 2026-11-10 starts 2026-11-09 21:00Z, so 5 h count)
    $b1 = $this->withToken($this->mech)->postJson('/api/v1/sites/mats/breakdowns', ['machineId' => $this->loom1, 'cause' => 'a', 'startedAt' => '2026-11-09T19:00:00Z'])->assertCreated()->json('breakdown.id');
    issueParts($this, $id, ['qty' => 3, 'breakdownId' => $b1])->assertCreated();
    $this->withToken($this->mech)->postJson("/api/v1/sites/mats/breakdowns/{$b1}/resolve", ['resolvedAt' => '2026-11-10T02:00:00Z'])->assertCreated();
    // loom2: still open since 05:00Z, now is 07:00Z
    $this->withToken($this->mech)->postJson('/api/v1/sites/mats/breakdowns', ['machineId' => $this->loom2, 'cause' => 'b', 'startedAt' => '2026-11-10T05:00:00Z'])->assertCreated();

    $q = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/downtime-cost?from=2026-11-10&to=2026-11-10')->assertOk()->json();
    $by = collect($q['machines'])->keyBy('machineId');
    expect($by[$this->loom1])->toMatchArray(['downtimeMinutes' => 300, 'breakdowns' => 1, 'sparesCost' => 36000]);
    expect($by[$this->loom2])->toMatchArray(['downtimeMinutes' => 120, 'breakdowns' => 1, 'sparesCost' => 0])
        ->and($q['totals'])->toMatchArray(['downtimeMinutes' => 420, 'sparesCost' => 36000]);
    $earlier = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/downtime-cost?from=2026-11-01&to=2026-11-08')->assertOk()->json();
    expect($earlier['machines'])->toBe([]);
    $this->withToken($this->manager)->getJson('/api/v1/sites/mats/downtime-cost')->assertOk()->assertJsonPath('to', '2026-11-10');
    foreach (['from=2026-11-11&to=2026-11-10', 'from=nope', 'to=2026-02-30', 'from=2020-01-01&to=2026-11-10'] as $qs) {
        $this->withToken($this->manager)->getJson("/api/v1/sites/mats/downtime-cost?{$qs}")->assertStatus(422);
    }
});

it('rebuilds parts, stock, issues, breakdowns and low_stock exceptions from the ledger exactly', function (): void {
    $id = stocked($this, 7);
    receiveParts($this, $id, ['qty' => 3, 'unitCost' => 15000])->assertCreated();
    $b = $this->withToken($this->mech)->postJson('/api/v1/sites/mats/breakdowns', ['machineId' => $this->loom1, 'cause' => 'Bearing', 'startedAt' => '2026-11-10T05:00:00Z'])->assertCreated()->json('breakdown.id');
    issueParts($this, $id, ['qty' => 4, 'breakdownId' => $b])->assertCreated();
    issueParts($this, $id, ['qty' => 4, 'machineId' => $this->loom2, 'reason' => 'PM'])->assertCreated();
    $this->withToken($this->sup)->postJson("/api/v1/sites/mats/breakdowns/{$b}/resolve")->assertCreated();
    sparePart($this, ['id' => $id, 'minLevel' => 8, 'name' => 'Bearing 6204 ZZ'])->assertCreated();
    $before = sparesRows();
    expect($before['parts'][0]->on_hand ?? $before['parts'][0]['on_hand'])->toEqual(2);
    app(Replay::class)->rebuild();
    expect(sparesRows())->toEqual($before);
});
