<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $this->users = fn (string $code): User => User::query()->where('code', $code)->firstOrFail();
    $token = fn (string $code): string => ($this->users)($code)->createToken('office', ['user'])->plainTextToken;
    $this->cashier = $token('u_cash_mats');
    $this->manager = $token('u_sm_mats');
    $this->otherManager = $token('u_sm_bags');
    $this->supervisor = $token('u_sup_mats');
    $this->ceo = $token('u_ceo1');
    $this->ceo2 = $token('u_ceo2');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->bags = (string) Site::query()->where('code', 'bags')->value('id');
});

function siteSettings(string $siteId, array $values): void
{
    DB::table('site_settings')->insert(['id' => (string) Str::uuid7(), 'site_id' => $siteId, ...$values]);
}

/** Append a foreign module's event and run it through projectors and policies, as the bus would. */
function foreignEvent(string $type, string $siteId, array $payload, string $at = '2026-11-09 08:00:00'): void
{
    $stored = app(EventStore::class)->append([new Event(
        id: (string) Str::uuid7(), type: $type, siteId: $siteId, actorId: null, deviceId: null,
        occurredAt: CarbonImmutable::parse($at), payload: $payload,
    )])[0];
    app(CommandBus::class)->project($stored);
    foreach (app(Registry::class)->policiesFor($type) as $policy) {
        foreach ($policy->react($stored) as $command) {
            app(CommandBus::class)->dispatch($command);
        }
    }
}

function attendanceMarked(string $siteId, string $personId, bool $crossSite = false, string $status = 'present', string $shift = 'day'): void
{
    foreignEvent('people.attendance_marked', $siteId, [
        'attendance_id' => (string) Str::uuid7(), 'person_id' => $personId, 'day' => '2026-11-09', 'shift_kind' => $shift,
        'shift_id' => "sh_2026-11-09_{$shift}", 'status' => $status, 'overtime_hours' => 0, 'cross_site' => $crossSite,
    ]);
}

function engagement(string $siteId, string $type, string $endDate, string $at = '2026-11-09 08:00:00'): string
{
    $id = (string) Str::uuid7();
    foreignEvent($type, $siteId, [
        'engagement_id' => $id, 'person_id' => (string) Str::uuid7(), 'type' => 'contract', 'start_date' => '2026-10-26',
        'end_date' => $endDate, 'term_days' => 15, 'rate_basis' => 'day', 'rate' => 15000, 'currency' => 'UGX', 'settlement_cycle' => 'weekly', 'renews_engagement_id' => null,
    ], $at);

    return $id;
}

/** @return list<array<string, mixed>> */
function exceptionRows(): array
{
    return DB::table('exceptions')->orderBy('id')->get()->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();
}

it('raises cash_variance on a short count and shows it in the CEO Approvals list', function (): void {
    siteSettings($this->mats, ['cash_variance' => 20000]);
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/entries', ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 100000])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 55000])->assertCreated();

    $list = $this->withToken($this->ceo)->getJson('/api/v1/exceptions')->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0])->toMatchArray(['kind' => 'cash_variance', 'status' => 'open', 'siteCode' => 'mats', 'severity' => 'warning', 'ownerRole' => 'site_manager', 'raisedBy' => null])
        ->and($list[0]['title'])->toBe('Cash close short by UGX 45,000')
        ->and($list[0]['facts']['variance'])->toEqual(-45000);
    $this->withToken($this->ceo)->getJson('/api/v1/exceptions?kind=ghost_worker')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->ceo)->getJson('/api/v1/exceptions?site=bags')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->manager)->getJson('/api/v1/sites/mats/exceptions')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($this->manager)->getJson('/api/v1/exceptions')->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/sites/mats/exceptions')->assertForbidden();
});

it('raises nothing when the variance is within tolerance', function (): void {
    siteSettings($this->mats, ['cash_variance' => 20000]);
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/entries', ['day' => '2026-11-09', 'direction' => 'in', 'category' => 'sale', 'amount' => 100000])->assertCreated();
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 80000])->assertCreated();
    expect(DB::table('exceptions')->count())->toBe(0);
});

it('raises ghost_worker once for a cross-site mark and for two sites on one day', function (): void {
    $person = (string) Str::uuid7();
    attendanceMarked($this->mats, $person, crossSite: true);
    attendanceMarked($this->mats, $person, crossSite: true, shift: 'night');
    expect(DB::table('exceptions')->where('kind', 'ghost_worker')->count())->toBe(1)
        ->and(DB::table('exceptions')->value('subject_ref'))->toBe("{$person}:2026-11-09");

    $other = (string) Str::uuid7();
    attendanceMarked($this->mats, $other);
    expect(DB::table('exceptions')->count())->toBe(1);
    DB::table('attendance')->insert([
        'id' => (string) Str::uuid7(), 'site_id' => $this->bags, 'person_id' => $other, 'day' => '2026-11-09', 'shift_kind' => 'day',
        'shift_id' => 'sh', 'status' => 'present', 'marked_at' => now(), 'event_id' => (string) Str::uuid7(),
    ]);
    attendanceMarked($this->mats, $other, shift: 'night');
    expect(DB::table('exceptions')->where('kind', 'ghost_worker')->count())->toBe(2);
});

it('raises contract_expiring when a contract starts inside the warning window, and resolves it when it ends', function (): void {
    $far = engagement($this->mats, 'people.engagement_started', '2026-11-20');
    $near = engagement($this->mats, 'people.engagement_renewed', '2026-11-12');
    expect(DB::table('exceptions')->pluck('subject_ref')->all())->toBe([$near])
        ->and(DB::table('exceptions')->value('title'))->toBe('Contract: contract ends in 3 days');

    siteSettings($this->bags, ['contract_warning_days' => 15]);
    engagement($this->bags, 'people.engagement_started', '2026-11-20');
    expect(DB::table('exceptions')->count())->toBe(2);

    foreignEvent('people.engagement_ended', $this->mats, ['engagement_id' => $near, 'person_id' => (string) Str::uuid7(), 'end_date' => '2026-11-10', 'reason' => 'done']);
    expect(DB::table('exceptions')->where('subject_ref', $near)->value('status'))->toBe('resolved')
        ->and(DB::table('exceptions')->where('subject_ref', $far)->exists())->toBeFalse();
});

it('resolves the old engagement\'s open contract_expiring exception when it is renewed', function (): void {
    $old = engagement($this->mats, 'people.engagement_started', '2026-11-12');
    $untouched = engagement($this->mats, 'people.engagement_started', '2026-11-12');
    $renewal = fn (?string $renews) => foreignEvent('people.engagement_renewed', $this->mats, [
        'engagement_id' => (string) Str::uuid7(), 'person_id' => (string) Str::uuid7(), 'type' => 'contract', 'start_date' => '2026-11-12',
        'end_date' => '2027-01-01', 'term_days' => 50, 'rate_basis' => 'day', 'rate' => 15000, 'currency' => 'UGX',
        'settlement_cycle' => 'weekly', 'renews_engagement_id' => $renews,
    ]);

    $renewal(null);
    expect(DB::table('exceptions')->where('status', 'open')->count())->toBe(2);

    $renewal((string) Str::uuid7());
    expect(DB::table('exceptions')->where('status', 'open')->count())->toBe(2);

    $renewal($old);
    expect(DB::table('exceptions')->where('subject_ref', $old)->value('status'))->toBe('resolved')
        ->and(DB::table('exceptions')->where('subject_ref', $untouched)->value('status'))->toBe('open');

    $before = exceptionRows();
    app(Replay::class)->rebuild();
    expect(exceptionRows())->toEqual($before);
});

it('scans engagements daily and raises contract_expiring once', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 06:00:00', 'Africa/Kampala'));
    $row = fn (string $end, string $status = 'active') => [
        'id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'person_id' => (string) Str::uuid7(), 'type' => 'contract',
        'start_date' => '2026-10-01', 'end_date' => $end, 'rate_basis' => 'day', 'rate' => 1, 'currency' => 'UGX',
        'settlement_cycle' => 'weekly', 'status' => $status,
    ];
    $rows = [$row('2026-11-11'), $row('2026-11-05'), $row('2026-11-30'), $row('2026-11-10', 'ended')];
    DB::table('engagements')->insert($rows);

    $this->artisan('people:contract-expiry')->expectsOutput('Raised 2.')->assertSuccessful();
    $this->artisan('people:contract-expiry')->expectsOutput('Raised 0.')->assertSuccessful();
    $raised = DB::table('exceptions')->orderBy('subject_ref')->get();
    expect($raised->pluck('subject_ref')->sort()->values()->all())->toBe(collect([$rows[0]['id'], $rows[1]['id']])->sort()->values()->all())
        ->and($raised->pluck('raised_by')->filter()->all())->toBe([])
        ->and(DB::table('events')->where('type', 'group.exception_raised')->whereNull('actor_id')->count())->toBe(2);
});

it('lets the right people decide and keeps separation of duties', function (): void {
    $raised = $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/exceptions', [
        'kind' => 'below_benchmark', 'severity' => 'warning', 'title' => 'Line 2 below benchmark', 'detail' => 'Output 60% of benchmark.', 'subjectRef' => 'line-2',
    ])->assertCreated()->json('exception');
    $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/exceptions', [
        'kind' => 'below_benchmark', 'severity' => 'warning', 'title' => 'Again', 'detail' => 'Again.', 'subjectRef' => 'line-2',
    ])->assertStatus(409);
    $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/exceptions', ['kind' => 'low_stock', 'severity' => 'info', 'title' => 'x'])
        ->assertStatus(422)->assertJsonPath('details.errors.detail', 'required, at most 2000 characters');
    $this->withToken($this->otherManager)->postJson('/api/v1/sites/mats/exceptions', ['kind' => 'low_stock', 'severity' => 'info', 'title' => 'x', 'detail' => 'y'])->assertForbidden();

    $url = "/api/v1/sites/mats/exceptions/{$raised['id']}/decision";
    $this->withToken($this->supervisor)->postJson($url, ['decision' => 'approve'])->assertForbidden();
    $this->withToken($this->otherManager)->postJson('/api/v1/sites/bags/exceptions/'.$raised['id'].'/decision', ['decision' => 'approve'])->assertNotFound();
    $this->withToken($this->manager)->postJson($url, ['decision' => 'reject'])->assertStatus(422);
    $this->withToken($this->manager)->postJson($url, ['decision' => 'reject', 'note' => 'Machine was down'])->assertCreated()
        ->assertJsonPath('exception.status', 'rejected')->assertJsonPath('exception.decisionReason', 'Machine was down');
    $this->withToken($this->ceo)->postJson($url, ['decision' => 'approve'])->assertStatus(409);

    // The raiser may not decide their own; a CEO-owned exception is not the site manager's to decide.
    $own = $this->withToken($this->ceo)->postJson('/api/v1/sites/mats/exceptions', [
        'kind' => 'gate_variance', 'severity' => 'critical', 'title' => 'Gate held', 'detail' => 'Scale 4% over.', 'ownerRole' => 'ceo',
    ])->assertCreated()->json('exception.id');
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/exceptions/{$own}/decision", ['decision' => 'approve'])->assertForbidden();
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/exceptions/{$own}/decision", ['decision' => 'approve'])->assertForbidden();
    $this->withToken($this->ceo2)->postJson("/api/v1/sites/mats/exceptions/{$own}/decision", ['decision' => 'acknowledge'])->assertCreated()
        ->assertJsonPath('exception.status', 'acknowledged');

    // Open first in the CEO list.
    $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/exceptions', ['kind' => 'low_stock', 'severity' => 'info', 'title' => 'Bearings low', 'detail' => '2 of 5.'])->assertCreated();
    expect(array_column($this->withToken($this->ceo)->getJson('/api/v1/exceptions')->json('data'), 'status'))->toBe(['open', 'acknowledged', 'rejected']);
});

it('holds an exception without closing it and still lets it be decided or auto-resolved', function (): void {
    $id = $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/exceptions', [
        'kind' => 'below_benchmark', 'severity' => 'warning', 'title' => 'Line 3 slow', 'detail' => 'Slow.', 'subjectRef' => 'line-3',
    ])->assertCreated()->json('exception.id');
    $url = "/api/v1/sites/mats/exceptions/{$id}/decision";
    $this->withToken($this->manager)->postJson($url, ['decision' => 'hold'])->assertStatus(422)->assertJsonPath('details.errors.note', 'required when holding');
    $this->withToken($this->manager)->postJson($url, ['decision' => 'hold', 'note' => 'Waiting for the engineer'])->assertCreated()
        ->assertJsonPath('exception.status', 'held')->assertJsonPath('exception.decision', 'hold')->assertJsonPath('exception.decisionReason', 'Waiting for the engineer');
    $this->withToken($this->manager)->postJson($url, ['decision' => 'hold', 'note' => 'Still waiting'])->assertCreated()->assertJsonPath('exception.status', 'held');
    $this->withToken($this->manager)->postJson($url, ['decision' => 'approve'])->assertCreated()->assertJsonPath('exception.status', 'approved');
    $this->withToken($this->manager)->postJson($url, ['decision' => 'hold', 'note' => 'x'])->assertStatus(409);

    // Held items list with the open ones, and a held contract_expiring is still resolved by the engagement ending.
    $near = engagement($this->mats, 'people.engagement_started', '2026-11-10');
    $contract = DB::table('exceptions')->where('subject_ref', $near)->value('id');
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/exceptions/{$contract}/decision", ['decision' => 'hold', 'note' => 'Discussing'])->assertCreated();
    expect(DB::table('exceptions')->where('id', $contract)->value('status'))->toBe('held');
    foreignEvent('people.engagement_ended', $this->mats, ['engagement_id' => $near, 'person_id' => 'p', 'end_date' => '2026-11-10', 'reason' => 'done']);
    expect(DB::table('exceptions')->where('id', $contract)->value('status'))->toBe('resolved');
});

it('rebuilds exceptions from the ledger exactly', function (): void {
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/cash/days/2026-11-09/close', ['counted' => 5000])->assertCreated();
    attendanceMarked($this->mats, (string) Str::uuid7(), crossSite: true);
    $near = engagement($this->mats, 'people.engagement_started', '2026-11-10');
    $id = DB::table('exceptions')->where('kind', 'cash_variance')->value('id');
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/exceptions/{$id}/decision", ['decision' => 'approve', 'note' => 'ok'])->assertCreated();
    foreignEvent('people.engagement_ended', $this->mats, ['engagement_id' => $near, 'person_id' => 'p', 'end_date' => '2026-11-10', 'reason' => 'done']);

    $before = exceptionRows();
    expect($before)->toHaveCount(3);
    app(Replay::class)->rebuild();
    expect(exceptionRows())->toEqual($before);
});

function briefRows(): array
{
    return DB::table('briefs')->orderBy('id')->get()->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();
}

it('lets the CEO send a brief and lists it, and replays it exactly', function (): void {
    $body = ['day' => '2026-11-09', 'channel' => 'copy', 'recipientIds' => [User::query()->where('code', 'u_ceo2')->value('id')], 'lines' => ['*Tanaad*', '', '• 12 on shift']];
    $r = $this->withToken($this->ceo)->postJson('/api/v1/briefs', $body);
    $r->assertCreated();
    expect($r->json('brief.day'))->toBe('2026-11-09')->and($r->json('brief.lines'))->toBe($body['lines'])
        ->and($r->json('brief.sentBy'))->toBe(($this->users)('u_ceo1')->id)->and($r->json('event_id'))->toBeString();
    $this->withToken($this->ceo2)->postJson('/api/v1/briefs', [...$body, 'channel' => 'log'])->assertCreated();

    $list = $this->withToken($this->ceo)->getJson('/api/v1/briefs?day=2026-11-09')->assertOk()->json('data');
    expect($list)->toHaveCount(2)->and($list[0])->toHaveKeys(['id', 'day', 'channel', 'recipientIds', 'lines', 'sentBy', 'sentAt']);
    $this->withToken($this->ceo)->getJson('/api/v1/briefs?day=2026-11-08')->assertOk()->assertJsonCount(0, 'data');

    $before = briefRows();
    expect($before)->toHaveCount(2);
    app(Replay::class)->rebuild();
    expect(briefRows())->toEqual($before);
});

it('rejects a bad brief and keeps it from site roles', function (): void {
    $this->withToken($this->ceo)->postJson('/api/v1/briefs', ['day' => 'yesterday', 'channel' => 'fax', 'lines' => []])->assertStatus(422);
    $ok = ['day' => '2026-11-09', 'channel' => 'copy', 'lines' => ['x']];
    $this->withToken($this->manager)->postJson('/api/v1/briefs', $ok)->assertForbidden();
    $this->withToken($this->manager)->getJson('/api/v1/briefs')->assertForbidden();
    expect(DB::table('briefs')->count())->toBe(0);
});
