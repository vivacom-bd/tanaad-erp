<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;

/** Phase 1 gate, HTTP only: offline attendance + cash batches in, idempotent replay, variance exception to the CEO, site isolation. */
beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
});

function gateLogin(Tests\TestCase $t, string $username): string
{
    return (string) $t->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'password'])->assertCreated()->json('token');
}

/** @return array<string, mixed> */
function gateEnvelope(string $type, array $payload, CarbonImmutable $at, array $extra = []): array
{
    return ['id' => (string) Str::uuid7(), 'type' => $type, 'occurred_at' => $at->toIso8601String(), 'payload' => $payload, ...$extra];
}

function gateBatch(Tests\TestCase $t, string $token, array $events, string $site = 'mats'): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/events", ['batch_id' => (string) Str::uuid7(), 'events' => $events]);
}

it('passes the Phase 1 gate end to end', function (): void {
    $day = '2026-11-07';
    $at = CarbonImmutable::parse('2026-11-07 08:00:00');

    // Setup (online): admin defines the person series, the supervisor registers three casuals.
    $admin = gateLogin($this, 'admin');
    $this->withToken($admin)->postJson('/api/v1/sites/mats/number-series', ['kind' => 'person', 'prefix' => 'MATSP', 'pad' => 4])->assertCreated();
    $sup = gateLogin($this, 'supmats');
    $people = [];
    foreach (['Sarah Achieng', 'John Okello', 'Grace Namono'] as $name) {
        $people[] = $this->withToken($sup)->postJson('/api/v1/sites/mats/people', [
            'fullName' => $name, 'sector' => 'Weaving', 'engagement' => ['rate' => 15000],
        ])->assertCreated()->json('person.id');
    }

    // 1. Offline attendance batch: client ids, past occurred_at, one batch.
    $attendance = [];
    foreach ($people as $i => $pid) {
        $attendance[] = gateEnvelope('people.attendance_marked', [
            'person_id' => $pid, 'day' => $day, 'shift_kind' => 'day', 'status' => $i === 2 ? 'half' : 'present',
        ], $at->addMinutes($i));
    }
    $batchId = (string) Str::uuid7();
    $send = fn () => $this->withToken($sup)->postJson('/api/v1/sites/mats/events', ['batch_id' => $batchId, 'events' => $attendance])->assertOk();

    $first = $send();
    expect(array_column($first->json('results'), 'status'))->toBe(['accepted', 'accepted', 'accepted']);
    $seqs = array_column($first->json('results'), 'seq');
    expect($seqs)->toBe(collect($seqs)->sort()->values()->all());
    $eventsAfterFirst = DB::table('events')->count();
    $rowsAfterFirst = DB::table('attendance')->count();

    $second = $send();
    expect(array_column($second->json('results'), 'status'))->toBe(['duplicate', 'duplicate', 'duplicate'])
        ->and(array_column($second->json('results'), 'seq'))->toBe($seqs)
        ->and(DB::table('events')->count())->toBe($eventsAfterFirst)
        ->and(DB::table('attendance')->count())->toBe($rowsAfterFirst)->toBe(3);

    // 2. Offline cash batch: opening balance 100000 in, spend 20000 out, count 55000 against expected 80000.
    $cashier = gateLogin($this, 'cashmats');
    $cash = [
        gateEnvelope('cash.entry_recorded', ['day' => $day, 'direction' => 'in', 'category' => 'sale', 'amount' => 100000], $at->addHours(1)),
        gateEnvelope('cash.entry_recorded', ['day' => $day, 'direction' => 'out', 'category' => 'fuel', 'amount' => 20000], $at->addHours(2)),
        gateEnvelope('cash.day_closed', ['day' => $day, 'counted' => 55000, 'denominations' => []], $at->addHours(9)),
    ];
    $cashRes = gateBatch($this, $cashier, $cash)->assertOk();
    expect(array_column($cashRes->json('results'), 'status'))->toBe(['accepted', 'accepted', 'accepted']);

    // 3. Read back.
    $headcount = $this->withToken($sup)->getJson("/api/v1/sites/mats/headcount?from={$day}&to={$day}")->assertOk()->json('data');
    expect($headcount)->toBe([['day' => $day, 'shift' => 'day', 'present' => 2, 'half' => 1, 'absent' => 0, 'headcount' => 2.5]]);

    $cashDay = $this->withToken($cashier)->getJson("/api/v1/sites/mats/cash/days/{$day}")->assertOk()->json();
    expect(json_encode($cashDay))->toContain('closed');
    $closed = DB::table('cash_days')->where('site_id', $this->mats)->where('day', $day)->first();
    expect($closed->status)->toBe('closed')
        ->and((float) $closed->expected_closing)->toBe(80000.0)
        ->and((float) $closed->counted_closing)->toBe(55000.0)
        ->and((float) $closed->variance)->toBe(-25000.0);

    $types = DB::table('events')->where('site_id', $this->mats)->orderBy('seq')->pluck('type')->all();
    $tail = array_slice($types, -5);
    expect($tail)->toBe(['cash.day_opened', 'cash.entry_recorded', 'cash.entry_recorded', 'cash.day_closed', 'group.exception_raised']);
    expect($types)->toContain('people.attendance_marked');

    // 4. CEO sees the cash_variance exception; supervisor and cashier may not decide it.
    $ceo = gateLogin($this, 'ceo1');
    $list = $this->withToken($ceo)->getJson('/api/v1/exceptions?kind=cash_variance&status=open')->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0])->toMatchArray(['kind' => 'cash_variance', 'status' => 'open', 'siteCode' => 'mats'])
        ->and($list[0]['facts']['day'])->toBe($day)
        ->and($list[0]['facts']['variance'])->toEqual(-25000);
    $exceptionId = $list[0]['id'];

    foreach ([$sup, $cashier] as $token) {
        $this->withToken($token)->postJson("/api/v1/sites/mats/exceptions/{$exceptionId}/decision", ['decision' => 'approve', 'note' => 'ok'])->assertForbidden();
    }
    $this->withToken($ceo)->postJson("/api/v1/sites/mats/exceptions/{$exceptionId}/decision", ['decision' => 'hold', 'note' => 'Recount tomorrow'])
        ->assertCreated()->assertJsonPath('exception.status', 'held');
    $this->withToken($ceo)->postJson("/api/v1/sites/mats/exceptions/{$exceptionId}/decision", ['decision' => 'approve', 'note' => 'Explained by the manager'])
        ->assertCreated()->assertJsonPath('exception.decisionReason', 'Explained by the manager');
    expect($this->withToken($ceo)->getJson('/api/v1/exceptions?kind=cash_variance&status=open')->json('data'))->toHaveCount(0);

    // 5. Cross-site isolation.
    gateBatch($this, $sup, [gateEnvelope('people.attendance_marked', ['person_id' => $people[0], 'day' => $day, 'shift_kind' => 'day', 'status' => 'present'], $at)], 'bags')
        ->assertForbidden();
    $this->withToken($sup)->postJson('/api/v1/sites/bags/attendance', ['personId' => $people[0], 'status' => 'present', 'day' => $day, 'shiftKind' => 'day'])->assertForbidden();
});
