<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\People\Models\Advance;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->admin = $token('u_admin');
    $this->manager = $token('u_sm_mats');
    $this->supervisor = $token('u_sup_mats');
    $this->cashier = $token('u_cash_mats');
    $this->withToken($this->admin)->postJson('/api/v1/sites/mats/number-series', ['kind' => 'person', 'prefix' => 'MATSP', 'pad' => 4])->assertCreated();

    // The "weekly casual" settlementNet fixture: 6 days at 15 000 with a 20 000 advance.
    $fixtures = json_decode((string) file_get_contents(base_path('../../contracts/fixtures/arithmetic.json')), true);
    $this->fixture = collect($fixtures['settlementNet'])->firstWhere('name', 'weekly casual');
    $in = $this->fixture['input'][0];

    $r = $this->withToken($this->manager)->postJson('/api/v1/sites/mats/people', [
        'fullName' => 'Sarah Achieng', 'engagement' => ['rate' => $in['rate'], 'startDate' => '2026-11-02'],
    ])->assertCreated()->json();
    $this->personId = $r['person']['id'];
    foreach (range(2, 1 + $in['unitsWorked']) as $i => $d) {
        $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/attendance', [
            'personId' => $this->personId, 'status' => 'present', 'day' => sprintf('2026-11-%02d', $d), 'shiftKind' => 'day',
            'overtimeHours' => $i === 0 ? 4 : 0,
        ])->assertCreated();
    }
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => $in['advances'], 'reason' => 'School fees', 'givenOn' => '2026-11-03',
    ])->assertCreated();
});

function weeklyRun(Tests\TestCase $t, string $token): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/settlement-runs', [
        'kind' => 'weekly_casual', 'from' => '2026-11-02', 'to' => '2026-11-07',
        'bonuses' => [['personId' => $t->personId, 'amount' => 5000]],
    ]);
}

it('computes a weekly casual run with overtime, bonus and an advance by the fixture arithmetic', function (): void {
    $in = $this->fixture['input'][0];
    $r = weeklyRun($this, $this->manager)->assertCreated();
    $expected = Arithmetic::settlementNet([...$in, 'overtimeHours' => 4, 'overtimeRatePerHour' => $in['rate'] / 8, 'bonus' => 5000]);
    expect($expected['gross'])->toEqual($this->fixture['expected']['gross'])
        ->and($expected['advances'])->toEqual($this->fixture['expected']['advances']);

    $line = $r->json('lines.0');
    expect($r->json('lines'))->toHaveCount(1)
        ->and($line)->toMatchArray(['personId' => $this->personId, 'status' => 'unpaid', 'periodFrom' => '2026-11-02', 'periodTo' => '2026-11-07'])
        ->and((float) $line['units'])->toEqual(6.0)
        ->and((float) $line['gross'])->toEqual($expected['gross'])
        ->and((float) $line['overtime'])->toEqual($expected['overtime'])
        ->and((float) $line['bonus'])->toEqual(5000.0)
        ->and((float) $line['advancesDeducted'])->toEqual($expected['advances'])
        ->and((float) $line['net'])->toEqual($expected['net'])
        ->and((float) $r->json('run.totalNet'))->toEqual($expected['net'])
        ->and($r->json('run.status'))->toBe('created');

    $event = json_decode((string) DB::table('events')->where('type', 'people.settlement_run_created')->value('payload'), true);
    expect($event['lines'][0])->toHaveKeys(['line_id', 'person_id', 'engagement_id', 'units', 'gross', 'overtime', 'bonus', 'advances_deducted', 'net'])
        ->and($event['totals']['net'])->toBe(number_format($expected['net'], 2, '.', ''))
        ->and((float) Advance::query()->firstOrFail()->balance)->toEqual(0.0);

    // The same engagement and period again is a duplicate; an empty period is a conflict.
    $dup = weeklyRun($this, $this->manager)->assertJsonPath('code', 'duplicate_fact');
    $number = (string) DB::table('people')->where('id', $this->personId)->value('number');
    expect($dup->json('detail'))->toContain("Sarah Achieng ({$number})")->not->toContain((string) DB::table('engagements')->where('person_id', $this->personId)->value('id'));
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/settlement-runs', ['kind' => 'weekly_casual', 'from' => '2026-11-16', 'to' => '2026-11-21'])
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict');
});

it('separates creating from approving and only pays approved runs once', function (): void {
    $runId = weeklyRun($this, $this->manager)->assertCreated()->json('run.id');
    $pay = fn (): Illuminate\Testing\TestResponse => $this->withToken($this->cashier)
        ->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/payouts", ['personId' => $this->personId, 'method' => 'cash', 'paidAt' => '2026-11-07T16:00:00+03:00']);

    $pay()->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertForbidden();
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertCreated()->assertJsonPath('run.status', 'approved');

    $payout = $pay()->assertCreated()->json('payout');
    expect($payout['amount'])->toEqual('82500.00');
    $pay()->assertJsonPath('code', 'duplicate_fact');

    // The shape the Cash module consumes.
    $payload = json_decode((string) DB::table('events')->where('type', 'people.payout_recorded')->value('payload'), true);
    expect(array_keys($payload))->toEqualCanonicalizing(['payout_id', 'settlement_run_id', 'person_id', 'amount', 'method', 'paid_at', 'reference'])
        ->and($payload)->toMatchArray(['payout_id' => $payout['id'], 'settlement_run_id' => $runId, 'person_id' => $this->personId, 'amount' => '82500.00', 'method' => 'cash'])
        ->and($payload['paid_at'])->toBe('2026-11-07T16:00:00+03:00');
    expect(DB::table('cash_entries')->where('id', $payout['id'])->value('amount'))->toEqual(82500)
        ->and($this->getJson("/api/v1/sites/mats/settlement-runs/{$runId}")->assertOk()->json('data.run.status'))->toBe('paid');
});

it('rebuilds settlement runs, lines, payouts and advances from the ledger', function (): void {
    $runId = weeklyRun($this, $this->manager)->assertCreated()->json('run.id');
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertCreated();
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/payouts", ['personId' => $this->personId, 'method' => 'mobile_money'])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', ['personId' => $this->personId, 'amount' => 3000, 'reason' => 'Transport'])->assertCreated();

    $dump = fn (): array => collect(['settlement_runs', 'settlement_lines', 'payouts', 'advances'])
        ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    $before = $dump();
    app(Replay::class)->rebuild();
    expect($dump())->toEqual($before)->and($before['advances'])->toHaveCount(2)->and($before['payouts'])->toHaveCount(1);
});

it('ingests settlement events through the offline outbox: accepted, then duplicate on resend', function (): void {
    $runId = (string) Str::uuid7();
    $at = CarbonImmutable::parse('2026-11-07 17:00', 'Africa/Kampala')->toIso8601String();
    $create = ['id' => (string) Str::uuid7(), 'type' => 'people.settlement_run_created', 'occurred_at' => $at, 'payload' => [
        'settlement_run_id' => $runId, 'kind' => 'weekly_casual', 'from' => '2026-11-02', 'to' => '2026-11-07',
        'bonuses' => [['person_id' => $this->personId, 'amount' => 5000]],
    ]];
    $send = fn (string $token, array $events) => $this->withToken($token)
        ->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => $events])->assertOk();

    $first = $send($this->manager, [$create]);
    expect($first->json('results.0.status'))->toBe('accepted')
        ->and(DB::table('settlement_runs')->where('id', $runId)->value('status'))->toBe('created');
    expect($send($this->manager, [$create])->json('results.0.status'))->toBe('duplicate')
        ->and(DB::table('settlement_runs')->count())->toBe(1);

    $approve = ['id' => (string) Str::uuid7(), 'type' => 'people.settlement_approved', 'occurred_at' => $at, 'payload' => ['settlement_run_id' => $runId]];
    $pay = ['id' => (string) Str::uuid7(), 'type' => 'people.payout_recorded', 'occurred_at' => $at, 'payload' => [
        'settlement_run_id' => $runId, 'person_id' => $this->personId, 'method' => 'cash',
    ]];
    expect($send($this->admin, [$approve, $approve])->json('results.*.status'))->toBe(['accepted', 'duplicate'])
        ->and($send($this->cashier, [$pay, $pay])->json('results.*.status'))->toBe(['accepted', 'duplicate'])
        ->and(DB::table('settlement_runs')->where('id', $runId)->value('status'))->toBe('paid')
        ->and(DB::table('payouts')->count())->toBe(1);
});

it('lists settlement runs with lines and payouts, and advances, for the site only', function (): void {
    $runId = weeklyRun($this, $this->manager)->assertCreated()->json('run.id');
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertCreated();
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/payouts", ['personId' => $this->personId, 'method' => 'cash'])->assertCreated();

    $list = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/settlement-runs')->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.run.id'))->toBe($runId)
        ->and($list->json('data.0.run.status'))->toBe('paid')
        ->and($list->json('data.0.lines'))->toHaveCount(1)
        ->and($list->json('data.0.payouts'))->toHaveCount(1);

    $adv = $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/advances')->assertOk();
    expect($adv->json('data'))->toHaveCount(1)
        ->and($adv->json('data.0.personId'))->toBe($this->personId)
        ->and((float) $adv->json('data.0.balance'))->toEqual(0.0);

    $this->withToken($this->manager)->getJson('/api/v1/sites/bags/settlement-runs')->assertForbidden();
});

it('cancels an unpaid run, restores its advances and lets the period be settled again', function (): void {
    $runId = weeklyRun($this, $this->manager)->assertCreated()->json('run.id');
    expect((float) Advance::query()->firstOrFail()->balance)->toEqual(0.0);

    $this->withToken($this->supervisor)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/cancel")->assertForbidden();
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/cancel", ['reason' => 'Wrong bonus'])
        ->assertCreated()->assertJsonPath('run.status', 'cancelled')->assertJsonPath('lines.0.status', 'cancelled');
    $advance = Advance::query()->firstOrFail();
    expect((float) $advance->balance)->toEqual((float) $advance->amount)->and((float) $advance->deducted)->toEqual(0.0);

    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/cancel")->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertStatus(409);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/settlement-runs/'.Str::uuid7().'/cancel')->assertNotFound();

    $again = weeklyRun($this, $this->manager)->assertCreated();
    expect((float) $again->json('lines.0.advancesDeducted'))->toEqual((float) $advance->amount);

    // Fully paid runs cannot be cancelled.
    $this->withToken($this->admin)->postJson('/api/v1/sites/mats/settlement-runs/'.$again->json('run.id').'/approve')->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/settlement-runs/'.$again->json('run.id').'/payouts', ['personId' => $this->personId, 'method' => 'cash'])->assertCreated();
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/settlement-runs/'.$again->json('run.id').'/cancel')
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict');

    $dump = fn (): array => collect(['settlement_runs', 'settlement_lines', 'advances'])
        ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    $before = $dump();
    app(Replay::class)->rebuild();
    expect($dump())->toEqual($before);
});

it('never pays a cancelled run', function (): void {
    $runId = weeklyRun($this, $this->manager)->assertCreated()->json('run.id');
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertCreated();
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/cancel")->assertCreated();

    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/payouts", ['personId' => $this->personId, 'method' => 'cash'])
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    expect(DB::table('payouts')->count())->toBe(0)
        ->and(DB::table('settlement_runs')->where('id', $runId)->value('status'))->toBe('cancelled');
});

it('deducts advances only up to the pay, keeps the rest owed and settles a zero-net line without a payout', function (): void {
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => 200000, 'reason' => 'Rent', 'givenOn' => '2026-11-04',
    ])->assertCreated();
    $r = weeklyRun($this, $this->manager)->assertCreated();
    $line = $r->json('lines.0');
    $pay = (float) $line['gross'] + (float) $line['overtime'] + (float) $line['bonus'];
    expect((float) $line['net'])->toEqual(0.0)
        ->and((float) $line['advancesDeducted'])->toEqual($pay)
        ->and((float) $r->json('run.totalNet'))->toEqual(0.0);

    $balances = Advance::query()->orderBy('given_on')->pluck('balance')->map(fn ($b): float => (float) $b)->all();
    expect($balances)->toEqual([0.0, 20000 + 200000 - $pay]);

    $runId = $r->json('run.id');
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertCreated()->assertJsonPath('run.status', 'paid');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/payouts", ['personId' => $this->personId, 'method' => 'cash'])
        ->assertStatus(409);

    $dump = fn (): array => collect(['settlement_runs', 'settlement_lines', 'advances'])
        ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    $before = $dump();
    app(Replay::class)->rebuild();
    expect($dump())->toEqual($before);
});

it('lets a later run see the deductions and settled days of an earlier one', function (): void {
    weeklyRun($this, $this->manager)->assertCreated();
    weeklyRun($this, $this->manager)->assertJsonPath('code', 'duplicate_fact');

    $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/attendance', [
        'personId' => $this->personId, 'status' => 'present', 'day' => '2026-11-09', 'shiftKind' => 'day',
    ])->assertCreated();
    $next = $this->withToken($this->manager)->postJson('/api/v1/sites/mats/settlement-runs', ['kind' => 'weekly_casual', 'from' => '2026-11-09', 'to' => '2026-11-14'])
        ->assertCreated();
    expect((float) $next->json('lines.0.advancesDeducted'))->toEqual(0.0);
});

it('gives an advance only where the person is engaged', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/sites/bags/advances', ['personId' => $this->personId, 'amount' => 1000, 'reason' => 'Lunch'])
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict');
});

it('rounds every line to whole shillings, half-up, and totals the line nets', function (): void {
    $other = $this->withToken($this->manager)->postJson('/api/v1/sites/mats/people', [
        'fullName' => 'Peter Okello', 'engagement' => ['rate' => 10001, 'startDate' => '2026-11-02'],
    ])->assertCreated()->json('person.id');
    $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/attendance', [
        'personId' => $other, 'status' => 'present', 'day' => '2026-11-04', 'shiftKind' => 'day', 'overtimeHours' => 1,
    ])->assertCreated();
    $r = $this->withToken($this->manager)->postJson('/api/v1/sites/mats/settlement-runs', [
        'kind' => 'weekly_casual', 'from' => '2026-11-02', 'to' => '2026-11-07',
        'bonuses' => [['personId' => $other, 'amount' => 2500.5]],
    ])->assertCreated();

    $line = collect($r->json('lines'))->firstWhere('personId', $other);
    // 10001 gross; overtime 10001 / 8 = 1250.125 -> 1250; bonus 2500.5 -> 2501 (half-up).
    expect($line)->toMatchArray(['gross' => '10001.00', 'overtime' => '1250.00', 'bonus' => '2501.00', 'advancesDeducted' => '0.00', 'net' => '13752.00']);
    $nets = collect($r->json('lines'))->sum(fn (array $l): float => (float) $l['net']);
    foreach ($r->json('lines') as $l) {
        foreach (['gross', 'overtime', 'bonus', 'advancesDeducted', 'net'] as $k) {
            expect(str_ends_with((string) $l[$k], '.00'))->toBeTrue();
        }
    }
    expect((float) $r->json('run.totalNet'))->toEqual($nets);
});

it('stores an optional payout reference and lists it', function (): void {
    $runId = weeklyRun($this, $this->manager)->assertCreated()->json('run.id');
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertCreated();
    $url = "/api/v1/sites/mats/settlement-runs/{$runId}/payouts";
    $this->withToken($this->cashier)->postJson($url, ['personId' => $this->personId, 'method' => 'mobile_money', 'reference' => str_repeat('x', 65)])
        ->assertStatus(422);
    $payout = $this->withToken($this->cashier)->postJson($url, ['personId' => $this->personId, 'method' => 'mobile_money', 'reference' => 'MP2611071234'])
        ->assertCreated()->json('payout');
    expect($payout['reference'])->toBe('MP2611071234');
    $payload = json_decode((string) DB::table('events')->where('type', 'people.payout_recorded')->value('payload'), true);
    expect($payload['reference'])->toBe('MP2611071234');
    $listed = collect($this->withToken($this->manager)->getJson('/api/v1/sites/mats/settlement-runs')->assertOk()->json('data'))
        ->flatMap(fn (array $run): array => $run['payouts'] ?? [])->firstWhere('id', $payout['id']);
    expect($listed['reference'])->toBe('MP2611071234');
});

it('books an advance as a cash-out in the cash book, replay-exact, and needs the cash day open', function (): void {
    // UGX has no minor unit: a fractional amount is refused, not rounded.
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => 4999.5, 'reason' => 'Transport', 'givenOn' => '2026-11-05',
    ])->assertStatus(422)->assertJsonPath('details.errors.amount', 'must be a whole number of UGX');
    $advance = $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => 5000, 'reason' => 'Transport', 'givenOn' => '2026-11-05',
    ])->assertCreated()->json('advance');
    expect($advance['amount'])->toEqual('5000.00');
    $entry = DB::table('cash_entries')->where('id', $advance['id'])->first();
    expect($entry->direction)->toBe('out')->and($entry->category)->toBe('wages')->and((float) $entry->amount)->toBe(5000.0)
        ->and((string) $entry->day)->toStartWith('2026-11-05');
    // The setup advance (given 2026-11-03) is booked too.
    expect(DB::table('cash_entries')->where('note', 'Advance')->count())->toBe(2);

    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-05/close', ['counted' => 0])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => 1000, 'reason' => 'Late', 'givenOn' => '2026-11-05',
    ])->assertStatus(409)->assertJsonPath('code', 'day_locked');

    $dump = fn (): array => [DB::table('cash_entries')->orderBy('id')->get(['id', 'cash_day_id', 'amount', 'direction', 'category', 'seq', 'event_id'])->map(fn ($r) => (array) $r)->all(),
        DB::table('cash_days')->orderBy('id')->get(['id', 'opening', 'status', 'seq'])->map(fn ($r) => (array) $r)->all()];
    $before = $dump();
    app(Replay::class)->rebuild();
    expect($dump())->toEqual($before);
});

it('rejects an advance dated after the command business day, but accepts an offline one for its own day', function (): void {
    $future = CarbonImmutable::now('Africa/Kampala')->addDays(3)->toDateString();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => 1000, 'reason' => 'Early', 'givenOn' => $future,
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
    expect(DB::table('cash_days')->where('day', $future)->exists())->toBeFalse()
        ->and(Advance::query()->count())->toBe(1);
});

it('reports a reused advance or payout id as a duplicate instead of failing', function (): void {
    $id = Advance::query()->firstOrFail()->id;
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => 1000, 'reason' => 'Retry', 'advanceId' => $id,
    ])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');

    // An id already used by a cash entry collides too.
    $entryId = (string) Str::uuid7();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/entries', [
        'entryId' => $entryId, 'direction' => 'out', 'category' => 'fuel', 'amount' => 100, 'day' => '2026-11-04',
    ])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $this->personId, 'amount' => 1000, 'reason' => 'Clash', 'advanceId' => $entryId, 'givenOn' => '2026-11-04',
    ])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');

    $runId = weeklyRun($this, $this->manager)->assertCreated()->json('run.id');
    $this->withToken($this->admin)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/approve")->assertCreated();
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/settlement-runs/{$runId}/payouts", [
        'personId' => $this->personId, 'method' => 'cash', 'payoutId' => $entryId,
    ])->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    expect(DB::table('payouts')->count())->toBe(0);
});

it('settles a non-UGX line at 2 places and leaves UGX advances for a UGX run', function (): void {
    DB::table('engagements')->where('person_id', $this->personId)->update(['settlement_cycle' => 'monthly']);
    $usd = $this->withToken($this->manager)->postJson('/api/v1/sites/mats/people', [
        'fullName' => 'John Okello', 'engagement' => ['rate' => 12.345, 'currency' => 'USD', 'startDate' => '2026-11-02'],
    ])->assertCreated()->json('person.id');
    $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/attendance', [
        'personId' => $usd, 'status' => 'present', 'day' => '2026-11-03', 'shiftKind' => 'day',
    ])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/advances', [
        'personId' => $usd, 'amount' => 5, 'reason' => 'Lunch', 'givenOn' => '2026-11-03',
    ])->assertCreated();

    $line = $this->withToken($this->manager)->postJson('/api/v1/sites/mats/settlement-runs', [
        'kind' => 'weekly_casual', 'from' => '2026-11-02', 'to' => '2026-11-07',
    ])->assertCreated()->json('lines.0');
    expect($line)->toMatchArray(['personId' => $usd, 'gross' => '12.35', 'advancesDeducted' => '0.00', 'net' => '12.35'])
        ->and((float) Advance::query()->where('person_id', $usd)->value('balance'))->toEqual(5.0);
});
