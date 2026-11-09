<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Cash\Models\CashDay;
use Tanaad\Cash\Models\CashEntry;
use Tanaad\Cash\Support\CashBook;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->cashier = $token('u_cash_mats');
    $this->manager = $token('u_sm_mats');
    $this->ceo = $token('u_ceo1');
    $this->supervisor = $token('u_sup_mats');
    $this->otherCashier = $token('u_cash_bags');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
});

function cashEntry(Tests\TestCase $t, string $token, array $body): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson('/api/v1/sites/mats/cash/entries', $body);
}

/** @return array<string, mixed> projections as plain rows, for replay comparison */
function cashRows(): array
{
    $strip = fn ($rows) => $rows->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();

    return [
        'days' => $strip(DB::table('cash_days')->orderBy('id')->get()),
        'entries' => $strip(DB::table('cash_entries')->orderBy('id')->get()),
        'locks' => DB::table('day_locks')->where('ledger', 'cash')->orderBy('day')->get(['site_id', 'day', 'locked_by_event', 'reopened_by_event'])->map(fn ($r) => (array) $r)->all(),
    ];
}

function payoutEvent(string $siteId, string $method, string $amount, string $paidAt): string
{
    $payoutId = (string) Str::uuid7();
    $stored = app(EventStore::class)->append([new Event(
        id: (string) Str::uuid7(), type: 'people.payout_recorded', siteId: $siteId, actorId: null, deviceId: null,
        occurredAt: CarbonImmutable::parse($paidAt), payload: [
            'payout_id' => $payoutId, 'settlement_run_id' => (string) Str::uuid7(), 'person_id' => (string) Str::uuid7(),
            'amount' => $amount, 'method' => $method, 'paid_at' => $paidAt,
        ],
    )]);
    app(CommandBus::class)->project($stored[0]);

    return $payoutId;
}

it('opens the day on the first entry, carrying the previous counted cash', function (): void {
    $r = cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 50000])->assertCreated();
    expect($r->json('entry.amount'))->toEqual(50000)->and($r->json('event_id'))->toBeString();
    expect(DB::table('events')->where('type', 'cash.day_opened')->count())->toBe(1);
    cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'out', 'category' => 'fuel', 'amount' => 12000.5])->assertCreated();
    expect(DB::table('events')->where('type', 'cash.day_opened')->count())->toBe(1)
        ->and(CashDay::query()->firstOrFail()->opening)->toEqual(0);

    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 37999.5])->assertCreated();
    cashEntry($this, $this->cashier, ['day' => '2026-11-10', 'direction' => 'in', 'category' => 'receipt', 'amount' => 1000])->assertCreated();
    expect((float) CashDay::query()->findOrFail(CashBook::dayId($this->mats, '2026-11-10'))->opening)->toBe(37999.5);

    $day = $this->withToken($this->ceo)->getJson('/api/v1/sites/mats/cash/days/2026-11-09')->assertOk();
    expect($day->json('entries'))->toHaveCount(2)
        ->and($day->json('totals.sales'))->toEqual(50000)
        ->and($day->json('totals.expenses'))->toEqual(12000.5)
        ->and($day->json('totals.expectedClosing'))->toEqual(37999.5)
        ->and($day->json('day.status'))->toBe('closed');

    $month = $this->withToken($this->manager)->getJson('/api/v1/sites/mats/cash/months/2026-11')->assertOk();
    expect($month->json('in'))->toEqual(51000)->and($month->json('out'))->toEqual(12000.5)
        ->and(collect($month->json('byCategory'))->firstWhere('category', 'fuel')['total'])->toEqual(12000.5);
});

it('closes with a variance computed by cashClose and carries it in the event', function (): void {
    cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 100000])->assertCreated();
    cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'out', 'category' => 'wages', 'amount' => 30000])->assertCreated();
    $r = $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 65000, 'denominations' => ['50000' => 1, '5000' => 3]])
        ->assertCreated();
    expect($r->json('day.status'))->toBe('closed')->and($r->json('day.variance'))->toEqual(-5000)->and($r->json('day.expectedClosing'))->toEqual(70000);

    $payload = json_decode((string) DB::table('events')->where('type', 'cash.day_closed')->value('payload'), true);
    expect($payload)->toMatchArray(['day' => '2026-11-09', 'expected_closing' => 70000, 'counted' => 65000, 'variance' => -5000, 'wages' => 30000, 'sales' => 100000])
        ->and(app(DayLocks::class)->isLocked($this->mats, 'cash', '2026-11-09'))->toBeTrue();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 1])->assertStatus(409);
});

it('rejects entries and corrections into a locked day, until the manager reopens it', function (): void {
    $e = cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 100])->json('entry');
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 100])->assertCreated();

    cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 5])
        ->assertStatus(409)->assertJsonPath('code', 'day_locked');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/cash/entries/{$e['id']}/corrections", ['direction' => 'in', 'category' => 'sale', 'amount' => 90, 'reason' => 'typo'])
        ->assertJsonPath('code', 'day_locked');

    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/reopen', ['reason' => 'late receipt'])->assertForbidden();
    $this->withToken($this->ceo)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/reopen', ['reason' => 'late receipt'])->assertForbidden();
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/reopen', [])->assertStatus(422);
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/reopen', ['reason' => 'late receipt'])
        ->assertCreated()->assertJsonPath('day.status', 'reopened')->assertJsonPath('day.reopenReason', 'late receipt');
    expect(DB::table('events')->where('type', 'cash.day_reopened')->value('reason'))->toBe('late receipt');
    cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 5])->assertCreated();
});

it('enforces who may write and read the cash book', function (): void {
    cashEntry($this, $this->supervisor, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 5])->assertForbidden();
    $this->withToken($this->otherCashier)->postJson('/api/v1/sites/mats/cash/entries', ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 5])->assertForbidden();
    $this->withToken($this->supervisor)->getJson('/api/v1/sites/mats/cash/days/2026-11-09')->assertForbidden();
    cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'sideways', 'category' => 'sale', 'amount' => -1])->assertStatus(422);
});

it('corrects an entry by appending, keeping the original', function (): void {
    $e = cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'out', 'category' => 'fuel', 'amount' => 900])->json('entry');
    $c = $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/cash/entries/{$e['id']}/corrections", [
        'direction' => 'out', 'category' => 'fuel', 'amount' => 90, 'reason' => 'extra zero',
    ])->assertCreated()->json('entry');

    expect($c['correctsEntryId'])->toBe($e['id'])
        ->and(CashEntry::query()->findOrFail($e['id'])->superseded_by)->toBe($c['id'])
        ->and(CashEntry::query()->count())->toBe(2);
    $original = DB::table('events')->where('type', 'cash.entry_recorded')->value('id');
    expect(DB::table('events')->where('type', 'cash.entry_corrected')->value('corrects_event_id'))->toBe($original);
    expect($this->withToken($this->cashier)->getJson('/api/v1/sites/mats/cash/days/2026-11-09')->json('totals.expenses'))->toEqual(90);
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/cash/entries/{$e['id']}/corrections", ['direction' => 'out', 'category' => 'fuel', 'amount' => 9, 'reason' => 'x'])
        ->assertStatus(409);
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/cash/entries/{$c['id']}/corrections", ['direction' => 'out', 'category' => 'fuel', 'amount' => 9])
        ->assertStatus(422);
});

it('books a cash payout from People as a wages entry and ignores other methods', function (): void {
    $payoutId = payoutEvent($this->mats, 'cash', '45000.00', '2026-11-12T16:30:00+03:00');
    payoutEvent($this->mats, 'mobile_money', '10000.00', '2026-11-12T16:31:00+03:00');

    $entry = CashEntry::query()->where('payout_id', $payoutId)->firstOrFail();
    expect($entry->category)->toBe('wages')->and($entry->direction)->toBe('out')->and((float) $entry->amount)->toBe(45000.0)
        ->and($entry->dayString())->toBe('2026-11-12')
        ->and(CashEntry::query()->count())->toBe(1)
        ->and(CashDay::query()->findOrFail(CashBook::dayId($this->mats, '2026-11-12'))->status)->toBe('open');

    $r = $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-12/close', ['counted' => 0])->assertCreated();
    expect($r->json('day.variance'))->toEqual(45000);

    $before = cashRows();
    app(Replay::class)->rebuild();
    expect(cashRows())->toEqual($before);
});

it('rebuilds the cash book from the ledger exactly', function (): void {
    $e = cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 700])->json('entry');
    $this->withToken($this->cashier)->postJson("/api/v1/sites/mats/cash/entries/{$e['id']}/corrections", ['direction' => 'in', 'category' => 'sale', 'amount' => 70, 'reason' => 'typo'])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 60])->assertCreated();
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/reopen', ['reason' => 'recount'])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 70])->assertCreated();
    cashEntry($this, $this->cashier, ['day' => '2026-11-10', 'direction' => 'out', 'category' => 'fuel', 'amount' => 10])->assertCreated();

    $before = cashRows();
    app(Replay::class)->rebuild();
    expect(cashRows())->toEqual($before)
        ->and((float) CashDay::query()->findOrFail(CashBook::dayId($this->mats, '2026-11-10'))->opening)->toBe(70.0);
});

it('ingests cash events offline with duplicate and rejected results', function (): void {
    $id = (string) Str::uuid7();
    $entry = ['id' => $id, 'type' => 'cash.entry_recorded', 'occurred_at' => '2026-11-09T09:00:00+03:00',
        'payload' => ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 2500]];
    $close = ['id' => (string) Str::uuid7(), 'type' => 'cash.day_closed', 'occurred_at' => '2026-11-09T18:00:00+03:00',
        'payload' => ['day' => '2026-11-09', 'counted' => 2500]];
    $late = ['id' => (string) Str::uuid7(), 'type' => 'cash.entry_recorded', 'occurred_at' => '2026-11-09T19:00:00+03:00',
        'payload' => ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 1]];
    $r = $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [$entry, $entry, $close, $late]])
        ->assertOk();

    expect($r->json('results.0.status'))->toBe('accepted')
        ->and($r->json('results.1.status'))->toBe('duplicate')
        ->and($r->json('results.2.status'))->toBe('accepted')
        ->and($r->json('results.3.status'))->toBe('rejected')
        ->and($r->json('results.3.code'))->toBe('day_locked')
        ->and(CashEntry::query()->count())->toBe(1)
        ->and(CashEntry::query()->firstOrFail()->event_id)->toBe($id)
        ->and((float) CashDay::query()->firstOrFail()->variance)->toBe(0.0);
});

it('sets the opening balance of a day without entries, then refuses once entries exist or it is closed', function (): void {
    $url = '/api/v1/sites/mats/cash/days/2026-11-09/opening';
    $this->withToken($this->supervisor)->postJson($url, ['amount' => 1000])->assertForbidden();
    $this->withToken($this->otherCashier)->postJson($url, ['amount' => 1000])->assertForbidden();
    $this->withToken($this->cashier)->postJson($url, ['amount' => -1])->assertStatus(422);

    $r = $this->withToken($this->cashier)->postJson($url, ['amount' => 250000])->assertCreated();
    expect($r->json('day.status'))->toBe('open')->and((float) CashDay::query()->findOrFail(CashBook::dayId($this->mats, '2026-11-09'))->opening)->toBe(250000.0)
        ->and(DB::table('events')->where('type', 'cash.day_opened')->count())->toBe(1);
    $this->withToken($this->ceo)->postJson($url, ['amount' => 200000])->assertCreated();
    $this->withToken($this->manager)->postJson($url, ['amount' => 300000])->assertCreated();
    expect(DB::table('events')->where('type', 'cash.day_opened')->count())->toBe(1);

    cashEntry($this, $this->cashier, ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 100])->assertCreated();
    $this->withToken($this->cashier)->postJson($url, ['amount' => 1])->assertStatus(409);
    $close = $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 300100])->assertCreated();
    expect($close->json('day.variance'))->toEqual(0);

    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-10/close', ['counted' => 0]);
    $before = cashRows();
    app(Replay::class)->rebuild();
    expect(cashRows())->toEqual($before);
});
