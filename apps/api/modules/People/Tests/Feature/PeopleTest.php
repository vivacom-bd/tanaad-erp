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
use Tanaad\People\Models\Attendance;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Models\Person;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class, ConfigSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->admin = $token('u_admin');
    $this->manager = $token('u_sm_mats');
    $this->bagsManager = $token('u_sm_bags');
    $this->supervisor = $token('u_sup_mats');
    $this->bagsSupervisor = $token('u_sup_bags');
    $this->cashier = $token('u_cash_mats');
    $this->ceo = $token('u_ceo1');
    foreach (['mats', 'bags'] as $site) {
        $this->withToken($this->admin)->postJson("/api/v1/sites/{$site}/number-series", ['kind' => 'person', 'prefix' => strtoupper($site).'P', 'pad' => 4])->assertCreated();
    }
});

function registerCasual(Tests\TestCase $t, string $token, string $site = 'mats', array $extra = []): array
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/people", [
        'fullName' => 'Sarah Achieng', 'phoneMomo' => '0772000000', 'sector' => 'Weaving', 'idNumber' => 'CM900',
        'engagement' => ['rate' => 15000], ...$extra,
    ])->assertCreated()->json();
}

function mark(Tests\TestCase $t, string $token, string $personId, array $extra = [], string $site = 'mats'): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/attendance", [
        'personId' => $personId, 'status' => 'present', 'day' => '2026-11-09', 'shiftKind' => 'day', ...$extra,
    ]);
}

it('registers a casual with a number and a first engagement', function (): void {
    $r = registerCasual($this, $this->supervisor);
    expect($r['person'])->toMatchArray(['fullName' => 'Sarah Achieng', 'number' => 'MATSP-0001', 'status' => 'active', 'avatarSeed' => 'SA'])
        ->and($r['engagement'])->toMatchArray(['type' => 'casual', 'rateBasis' => 'day', 'settlementCycle' => 'weekly', 'currency' => 'UGX', 'status' => 'active']);
    expect(DB::table('events')->where('type', 'people.engagement_started')->count())->toBe(1);
    expect(registerCasual($this, $this->supervisor)['person']['number'])->toBe('MATSP-0002');

    $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/people', ['fullName' => 'X', 'engagement' => ['type' => 'permanent', 'rateBasis' => 'month', 'rate' => 1, 'settlementCycle' => 'monthly']])->assertForbidden();
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/people', ['engagement' => ['rate' => -1]])->assertStatus(422)
        ->assertJsonPath('details.errors.full_name', 'required')->assertJsonPath('details.errors', fn (array $e): bool => isset($e['engagement.rate']));
    $this->withToken($this->cashier)->postJson('/api/v1/sites/mats/people', ['fullName' => 'X', 'engagement' => ['rate' => 1]])->assertForbidden();
    $this->withToken($this->bagsSupervisor)->postJson('/api/v1/sites/mats/people', ['fullName' => 'X', 'engagement' => ['rate' => 1]])->assertForbidden();
});

it('rejects registration where no person number series is defined', function (): void {
    DB::table('number_series')->where('kind', 'person')->delete();
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/people', ['fullName' => 'X', 'engagement' => ['rate' => 1]])
        ->assertJsonPath('code', 'stale_config');
});

it('starts, renews and ends engagements', function (): void {
    $r = registerCasual($this, $this->manager);
    $personId = $r['person']['id'];
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/people/{$personId}/engagements", ['type' => 'contract', 'rateBasis' => 'month', 'rate' => 400000, 'currency' => 'UGX', 'settlementCycle' => 'monthly'])
        ->assertStatus(409)->assertJsonPath('code', 'state_conflict');
    $this->withToken($this->supervisor)->postJson("/api/v1/sites/mats/engagements/{$r['engagement']['id']}/renew", ['termDays' => 30])->assertForbidden();

    $renewed = $this->withToken($this->manager)->postJson("/api/v1/sites/mats/engagements/{$r['engagement']['id']}/renew", ['termDays' => 30, 'startDate' => '2026-11-10'])
        ->assertCreated()->json('engagement');
    expect($renewed)->toMatchArray(['startDate' => '2026-11-10', 'endDate' => '2026-12-10', 'termDays' => 30, 'status' => 'active', 'renewsEngagementId' => $r['engagement']['id']])
        ->and(Engagement::query()->find($r['engagement']['id'])->status)->toBe('renewed');
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/engagements/{$r['engagement']['id']}/renew", ['termDays' => 30])->assertStatus(409);

    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/engagements/{$renewed['id']}/end", ['endDate' => '2026-11-20', 'reason' => 'Left'])
        ->assertCreated()->assertJsonPath('engagement.status', 'ended')->assertJsonPath('engagement.endReason', 'Left');
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/engagements/{$renewed['id']}/end", [])->assertStatus(409);

    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/people/{$personId}/engagements", ['type' => 'contract', 'rateBasis' => 'month', 'rate' => 400000, 'currency' => 'UGX', 'settlementCycle' => 'monthly', 'termDays' => 90, 'startDate' => '2026-12-01'])
        ->assertCreated()->assertJsonPath('engagement.endDate', '2027-03-01');
    $this->withToken($this->manager)->postJson('/api/v1/sites/mats/engagements/'.Str::uuid7().'/end', [])->assertNotFound();
});

it('marks attendance, rejects duplicates and accepts a correction with a reason', function (): void {
    $personId = registerCasual($this, $this->supervisor)['person']['id'];
    $first = mark($this, $this->supervisor, $personId, ['overtimeHours' => 2])->assertCreated()->json();
    expect($first['attendance'])->toMatchArray(['status' => 'present', 'shiftId' => 'sh_mats_2026-11-09_day', 'overtimeHours' => 2, 'crossSite' => false]);

    mark($this, $this->supervisor, $personId)->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    mark($this, $this->supervisor, $personId, ['status' => 'half', 'reason' => ''])->assertStatus(422);
    mark($this, $this->supervisor, $personId, ['status' => 'half', 'reason' => 'Left at noon', 'correctsEventId' => (string) Str::uuid7()])->assertStatus(409);
    mark($this, $this->supervisor, $personId, ['status' => 'half', 'reason' => 'Left at noon'])->assertCreated()->assertJsonPath('attendance.status', 'half');
    $corrected = DB::table('events')->where('type', 'people.attendance_corrected')->first();
    expect($corrected->corrects_event_id)->toBe($first['event_id'])->and($corrected->reason)->toBe('Left at noon')
        ->and(Attendance::query()->count())->toBe(1);

    mark($this, $this->supervisor, $personId, ['shiftKind' => 'night', 'reason' => 'x'])->assertNotFound();
    mark($this, $this->supervisor, $personId, ['shiftKind' => 'evening'])->assertStatus(422);
    mark($this, $this->cashier, $personId, ['shiftKind' => 'night'])->assertForbidden();
});

it('rejects marking an archived person or one not engaged at the site, and flags cross-site presence', function (): void {
    $personId = registerCasual($this, $this->manager)['person']['id'];
    mark($this, $this->bagsSupervisor, $personId, [], 'bags')->assertStatus(409)->assertJsonPath('code', 'state_conflict');

    $bags = Site::query()->where('code', 'bags')->value('id');
    $shared = registerCasual($this, $this->manager, 'mats', ['fullName' => 'Peter Okot', 'shared' => true, 'sharedSites' => [$bags]])['person']['id'];
    mark($this, $this->supervisor, $shared)->assertCreated();
    mark($this, $this->bagsSupervisor, $shared, [], 'bags')->assertCreated()->assertJsonPath('attendance.crossSite', true);

    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/people/{$personId}/archive", ['reason' => 'Left'])->assertCreated()->assertJsonPath('person.status', 'archived');
    mark($this, $this->supervisor, $personId)->assertStatus(409)->assertJsonPath('code', 'state_conflict');
});

it('archives and reactivates by role', function (): void {
    $personId = registerCasual($this, $this->manager)['person']['id'];
    $this->withToken($this->supervisor)->postJson("/api/v1/sites/mats/people/{$personId}/archive")->assertForbidden();
    $this->withToken($this->bagsManager)->postJson("/api/v1/sites/mats/people/{$personId}/archive")->assertForbidden();
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/people/{$personId}/archive")->assertCreated();
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/people/{$personId}/archive")->assertStatus(409);
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/people/{$personId}/reactivate")->assertCreated()->assertJsonPath('person.status', 'active');
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/people/{$personId}/reactivate")->assertStatus(409);
});

it('counts heads per day and shift', function (): void {
    foreach (['present', 'half', 'absent'] as $i => $status) {
        $id = registerCasual($this, $this->supervisor, 'mats', ['fullName' => "P {$i}"])['person']['id'];
        mark($this, $this->supervisor, $id, ['status' => $status])->assertCreated();
    }
    $this->withToken($this->supervisor)->getJson('/api/v1/sites/mats/headcount?from=2026-11-09')->assertOk()
        ->assertJsonPath('data', [['day' => '2026-11-09', 'shift' => 'day', 'present' => 1, 'half' => 1, 'absent' => 1, 'headcount' => 1.5]]);
    $this->withToken($this->supervisor)->getJson('/api/v1/sites/mats/headcount?from=nope')->assertStatus(422);
});

it('archives absent casuals through the ledger using the site setting', function (): void {
    $mats = Site::query()->where('code', 'mats')->firstOrFail();
    DB::table('site_settings')->where('site_id', $mats->id)->update(['archive_after_absent_days' => 5]);
    $today = $mats->calendar()->businessDay(CarbonImmutable::now());
    $old = registerCasual($this, $this->manager, 'mats', ['fullName' => 'Gone Away', 'joinedOn' => CarbonImmutable::parse($today)->subDays(20)->toDateString()])['person']['id'];
    $recent = registerCasual($this, $this->manager, 'mats', ['fullName' => 'Still Here', 'joinedOn' => CarbonImmutable::parse($today)->subDays(20)->toDateString()])['person']['id'];
    mark($this, $this->supervisor, $recent, ['day' => CarbonImmutable::parse($today)->subDays(2)->toDateString()])->assertCreated();

    $this->artisan('people:archive-absent')->assertSuccessful();
    expect(Person::query()->find($old)->status)->toBe('archived')
        ->and(Person::query()->find($recent)->status)->toBe('active')
        ->and(DB::table('events')->where('type', 'people.person_archived')->whereNull('actor_id')->count())->toBe(1);

    DB::table('site_settings')->where('site_id', $mats->id)->update(['archive_after_absent_days' => 0]);
    config(['tanaad.people.archive_after_absent_days' => 1]);
    $this->artisan('people:archive-absent')->assertSuccessful();
    expect(Person::query()->find($recent)->status)->toBe('archived');
});

it('ingests offline People events with accepted, duplicate and rejected results', function (): void {
    $personId = (string) Str::uuid7();
    $regId = (string) Str::uuid7();
    $markId = (string) Str::uuid7();
    $at = CarbonImmutable::parse('2026-11-09 09:00', 'Africa/Kampala')->toIso8601String();
    $events = [
        ['id' => $regId, 'type' => 'people.person_registered', 'occurred_at' => $at, 'provisional_ref' => 'tmp-1',
            'payload' => ['person_id' => $personId, 'full_name' => 'Offline Okello', 'engagement' => ['rate' => 12000]]],
        ['id' => $markId, 'type' => 'people.attendance_marked', 'occurred_at' => $at, 'payload' => ['person_id' => $personId, 'status' => 'present']],
        ['id' => $markId, 'type' => 'people.attendance_marked', 'occurred_at' => $at, 'payload' => ['person_id' => $personId, 'status' => 'present']],
        ['id' => (string) Str::uuid7(), 'type' => 'people.attendance_marked', 'occurred_at' => $at, 'payload' => ['person_id' => $personId, 'status' => 'present']],
        ['id' => (string) Str::uuid7(), 'type' => 'people.attendance_corrected', 'occurred_at' => $at, 'reason' => 'Half day',
            'corrects_event_id' => $markId, 'payload' => ['person_id' => $personId, 'status' => 'half']],
    ];
    $r = $this->withToken($this->supervisor)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => $events])->assertOk();
    expect(array_column($r->json('results'), 'status'))->toBe(['accepted', 'accepted', 'duplicate', 'rejected', 'accepted'])
        ->and($r->json('results.0.assigned'))->toBe(['tmp-1' => 'MATSP-0001'])
        ->and($r->json('results.3.code'))->toBe('duplicate_fact')
        ->and(Attendance::query()->where('person_id', $personId)->value('shift_id'))->toBe('sh_mats_2026-11-09_day')
        ->and(Attendance::query()->where('person_id', $personId)->value('status'))->toBe('half')
        ->and(Engagement::query()->where('person_id', $personId)->count())->toBe(1);
});

it('rebuilds people, engagements and attendance from the ledger', function (): void {
    $r = registerCasual($this, $this->manager);
    $id = $r['person']['id'];
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/engagements/{$r['engagement']['id']}/renew", ['termDays' => 14])->assertCreated();
    mark($this, $this->supervisor, $id)->assertCreated();
    mark($this, $this->supervisor, $id, ['status' => 'half', 'reason' => 'Late'])->assertCreated();
    $this->withToken($this->manager)->postJson("/api/v1/sites/mats/people/{$id}/archive")->assertCreated();

    $dump = fn (): array => collect(['people', 'engagements', 'attendance'])
        ->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
    $before = $dump();
    app(Replay::class)->rebuild();
    expect($dump())->toEqual($before)->and($before['engagements'])->toHaveCount(2);
});
