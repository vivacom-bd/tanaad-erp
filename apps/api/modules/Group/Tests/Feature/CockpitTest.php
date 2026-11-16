<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tanaad\Cash\Database\Seeders\CashSeeder;
use Tanaad\Config\Database\Seeders\ConfigSeeder;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Group\Support\MorningBrief;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Orders\Database\Seeders\OrdersSeeder;
use Tanaad\People\Database\Seeders\PeopleSeeder;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\EventStore;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-10 09:00:00');
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $this->ceo = $token('u_ceo1');
    $this->manager = $token('u_sm_mats');
    $this->cashier = $token('u_cash_mats');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->bags = (string) Site::query()->where('code', 'bags')->value('id');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function ckEvent(string $type, ?string $siteId, array $payload, string $at = '2026-11-09 08:00:00', ?string $reason = null, ?string $actor = null): string
{
    $id = (string) Str::uuid7();
    app(EventStore::class)->append([new Event(id: $id, type: $type, siteId: $siteId, actorId: $actor, deviceId: null,
        occurredAt: CarbonImmutable::parse($at), payload: $payload, reason: $reason)]);

    return $id;
}

function ckException(string $site, string $severity, string $status = 'open', string $owner = 'site_manager'): void
{
    $id = (string) Str::uuid7();
    DB::table('exceptions')->insert(['id' => $id, 'site_id' => $site, 'kind' => 'cash_variance', 'severity' => $severity, 'title' => "t-{$severity}", 'detail' => 'd',
        'subject_ref' => $id, 'facts' => '{}', 'owner_role' => $owner, 'status' => $status, 'raised_at' => '2026-11-09 08:00:00', 'raised_event_id' => (string) Str::uuid7(), 'seq' => 1]);
}

it('builds per-site cockpit tiles from read tables and is group-only', function (): void {
    $mat = DB::table('production_entries')->getConnection();
    DB::table('production_entries')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'shift_id' => 'sh1', 'day' => '2026-11-10', 'shift_kind' => 'day',
        'machine_id' => (string) Str::uuid7(), 'stage_code' => 'wall', 'good_units' => 40, 'reject_units' => 2, 'output_kg' => 120.5, 'below_benchmark' => true, 'event_id' => (string) Str::uuid7(), 'at' => now()]);
    DB::table('attendance')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'person_id' => (string) Str::uuid7(), 'day' => '2026-11-10', 'shift_kind' => 'day', 'shift_id' => 'sh1',
        'status' => 'present', 'overtime_hours' => 2, 'marked_at' => now(), 'event_id' => (string) Str::uuid7()]);
    $day = (string) Str::uuid7();
    DB::table('cash_days')->insert(['id' => $day, 'site_id' => $this->mats, 'day' => '2026-11-10', 'currency' => 'UGX', 'opening' => 1000, 'status' => 'open']);
    foreach ([['in', 500], ['out', 200]] as [$dir, $amt]) {
        DB::table('cash_entries')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'cash_day_id' => $day, 'day' => '2026-11-10', 'direction' => $dir, 'category' => 'x',
            'amount' => $amt, 'currency' => 'UGX', 'event_id' => (string) Str::uuid7(), 'at' => now()]);
    }
    ckException($this->mats, 'critical');
    ckException($this->mats, 'warning', 'held');
    ckException($this->mats, 'info', 'resolved');
    DB::table('stock_on_hand')->insert(['id' => 'a', 'site_id' => $this->mats, 'product_id' => (string) Str::uuid7(), 'bundles' => 7, 'kg' => 70]);
    DB::table('breakdowns')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'machine_id' => (string) Str::uuid7(), 'cause' => 'belt', 'started_at' => now(), 'event_id' => (string) Str::uuid7()]);

    $r = $this->withToken($this->ceo)->getJson('/api/v1/group/cockpit')->assertOk()->json('data');
    $mats = collect($r['sites'])->firstWhere('siteCode', 'mats');
    expect($r['day'])->toBe('2026-11-10')->and($r['sites'])->toHaveCount(count(Site::query()->get()))
        ->and($mats['output'])->toMatchArray(['goodUnits' => 40, 'rejectUnits' => 2, 'belowBenchmark' => 1])
        ->and($mats['headcount'])->toMatchArray(['present' => 1, 'total' => 1, 'overtimeHours' => 2])
        ->and($mats['cash'])->toMatchArray(['inSafe' => 1300, 'closeStatus' => 'open', 'asOf' => '2026-11-10'])
        ->and($mats['exceptions'])->toMatchArray(['critical' => 1, 'warning' => 1, 'held' => 1, 'total' => 2])
        ->and($mats['stock'])->toMatchArray(['bundles' => 7, 'products' => 1])
        ->and($mats['machinesDown']['count'])->toBe(1)
        ->and($mats['dispatches'])->toMatchArray(['pending' => 0, 'held' => 0])
        ->and($mats['receivables'])->toEqual(['open' => 0, 'count' => 0, 'byCurrency' => []]);
    $this->withToken($this->manager)->getJson('/api/v1/group/cockpit')->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/group/cockpit')->assertForbidden();
});

it('lists open and held exceptions by severity for the group only', function (): void {
    ckException($this->mats, 'info');
    ckException($this->bags, 'critical', 'held', 'ceo');
    ckException($this->mats, 'warning');
    ckException($this->mats, 'critical', 'approved');
    $r = $this->withToken($this->ceo)->getJson('/api/v1/group/approvals')->assertOk()->json('data');
    expect(array_column($r['exceptions'], 'severity'))->toBe(['critical', 'warning', 'info'])
        ->and($r['exceptions'][0]['needsGroup'])->toBeTrue()->and($r['exceptions'][1]['needsGroup'])->toBeFalse()
        ->and($r['orders'])->toBe([])->and($r['counts'])->toMatchArray(['exceptions' => 3, 'critical' => 1]);
    $this->withToken($this->manager)->getJson('/api/v1/group/approvals')->assertForbidden();
});

it('shows a readable, scoped, paginated audit feed without secrets', function (): void {
    $u = User::query()->where('code', 'u_cash_mats')->firstOrFail();
    ckEvent('cash.day_closed', $this->mats, ['day' => '2026-11-09', 'variance' => -500, 'pin_hash' => 'SECRET1', 'counted_closing' => 10], '2026-11-09 10:00:00', actor: (string) $u->id);
    ckEvent('dispatch.gate_overridden', $this->mats, ['order_ref' => 'SO-1', 'signature_hash' => 'SECRET2'], '2026-11-09 11:00:00', 'owner OK');
    ckEvent('cash.entry_recorded', $this->bags, ['direction' => 'in', 'amount' => 5, 'currency' => 'UGX', 'category' => 'sale'], '2026-11-08 09:00:00');
    ckEvent('identity.pin_set', $this->mats, ['pin' => '1234', 'pin_hash' => 'SECRET3'], '2026-11-09 12:00:00');
    ckEvent('group.brief_sent', null, ['day' => '2026-11-09', 'channel' => 'log'], '2026-11-09 06:30:00');

    $all = $this->withToken($this->ceo)->getJson('/api/v1/group/audit?to=2026-11-09')->assertOk();
    expect($all->getContent())->not->toContain('SECRET')->not->toContain('1234');
    $rows = collect($all->json('data'));
    $closed = $rows->firstWhere('type', 'cash.day_closed');
    expect($rows)->toHaveCount(5)->and($all->json('meta'))->toMatchArray(['page' => 1, 'total' => 5])
        ->and($closed)->toMatchArray(['flag' => 'variance', 'siteCode' => 'mats', 'actorName' => $u->name])
        ->and($closed['summary'])->toBe('Closed the cash day 2026-11-09, variance -500')
        ->and($rows->firstWhere('type', 'dispatch.gate_overridden'))->toMatchArray(['flag' => 'override', 'reason' => 'owner OK'])
        ->and($rows->firstWhere('type', 'identity.pin_set'))->toMatchArray(['summary' => 'Identity pin set', 'facts' => []])
        ->and($rows->firstWhere('type', 'group.brief_sent')['actorName'])->toBe('System');

    expect($this->withToken($this->ceo)->getJson('/api/v1/group/audit?to=2026-11-09&site=bags')->json('data'))->toHaveCount(1);
    expect($this->withToken($this->ceo)->getJson('/api/v1/group/audit?to=2026-11-09&type=cash')->json('data'))->toHaveCount(2);
    $this->withToken($this->ceo)->getJson('/api/v1/group/audit?page=1001')->assertStatus(422)->assertJsonPath('details.errors.page', 'must be between 1 and 1000');
    $this->withToken($this->ceo)->getJson('/api/v1/group/audit?page=1000')->assertOk();
    expect($this->withToken($this->ceo)->getJson('/api/v1/group/audit?to=2026-11-09&type=cash.entry_recorded')->json('data'))->toHaveCount(1);
    expect($this->withToken($this->ceo)->getJson('/api/v1/group/audit?from=2026-11-09&to=2026-11-09')->json('data'))->toHaveCount(4);
    $p2 = $this->withToken($this->ceo)->getJson('/api/v1/group/audit?to=2026-11-09&per_page=2&page=3')->assertOk();
    expect($p2->json('data'))->toHaveCount(1)->and($p2->json('meta'))->toMatchArray(['lastPage' => 3, 'total' => 5]);
    $this->withToken($this->ceo)->getJson('/api/v1/group/audit?from=nope')->assertStatus(422);

    $mine = $this->withToken($this->manager)->getJson('/api/v1/group/audit?to=2026-11-09')->assertOk();
    expect($mine->json('data'))->toHaveCount(3)->and(collect($mine->json('data'))->pluck('siteCode')->unique()->all())->toBe(['mats']);
    $this->withToken($this->manager)->getJson('/api/v1/group/audit?to=2026-11-09&site=bags')->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/group/audit')->assertForbidden();
});

it('builds the morning brief for yesterday per site', function (): void {
    DB::table('production_entries')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'shift_id' => 's', 'day' => '2026-11-09', 'shift_kind' => 'day',
        'machine_id' => (string) Str::uuid7(), 'stage_code' => 'w', 'good_units' => 80, 'output_kg' => 10, 'benchmark' => 100, 'event_id' => (string) Str::uuid7(), 'at' => now()]);
    ckEvent('dispatch.gate_held', $this->mats, ['order_ref' => 'A'], '2026-11-09 09:00:00');
    ckEvent('dispatch.delivered', $this->mats, ['order_ref' => 'B'], '2026-11-09 15:00:00');
    ckException($this->mats, 'warning');
    $b = app(MorningBrief::class)->build('2026-11-10');
    $mats = collect($b['sites'])->firstWhere('siteCode', 'mats');
    expect($b['for'])->toBe('2026-11-09')->and($mats['production']['benchmarkPct'])->toBe(80)
        ->and($mats['gateHolds'])->toBe(1)->and($mats['delivered'])->toBe(1)->and($mats['exceptions'])->toBe(['raised' => 1, 'open' => 1])
        ->and($mats['newOrders'])->toBe(0)
        ->and($b['lines'][0])->toStartWith('Morning brief for Tue 10 Nov 2026');
});

it('sends the morning brief once per date under the system context', function (): void {
    $this->artisan('group:morning-brief')->assertSuccessful();
    $this->artisan('group:morning-brief')->expectsOutputToContain('already sent')->assertSuccessful();
    expect(DB::table('briefs')->count())->toBe(1)->and(DB::table('briefs')->value('day'))->toStartWith('2026-11-10')
        ->and(DB::table('briefs')->value('id'))->toBe(MorningBrief::idFor('2026-11-10'))
        ->and(DB::table('events')->where('type', 'group.brief_sent')->count())->toBe(1)
        ->and(DB::table('events')->where('type', 'group.brief_sent')->value('actor_id'))->toBeNull();
    $this->artisan('group:morning-brief', ['--date' => '2026-11-11'])->assertSuccessful();
    expect(DB::table('briefs')->count())->toBe(2);
    $this->artisan('group:morning-brief', ['--date' => 'bad'])->assertFailed();
    // Replay-free sanity: a manual brief from the CEO is still refused for other roles.
    $this->withToken($this->manager)->postJson('/api/v1/briefs', ['day' => '2026-11-10', 'channel' => 'log', 'lines' => ['x']])->assertForbidden();
    expect(Schema::hasTable('briefs'))->toBeTrue();
});

it('shows real orders: awaiting approval on the credit desk, unpaid invoice balances, and the over-limit decision on the order', function (): void {
    foreach ([ConfigSeeder::class, PeopleSeeder::class, CashSeeder::class, OrdersSeeder::class] as $seeder) {
        app()->call([new $seeder, 'run']);
    }
    $waiting = DB::table('orders')->whereIn('status', ['awaiting_approval', 'held'])->count();
    $r = $this->withToken($this->ceo)->getJson('/api/v1/group/approvals')->assertOk()->json('data');
    $juba = collect($r['orders'])->firstWhere('id', OrdersSeeder::id('o_ss1_juba'));
    expect($waiting)->toBeGreaterThan(0)->and($r['orders'])->toHaveCount($waiting)->and($r['counts']['orders'])->toBe($waiting)
        ->and($juba)->toMatchArray(['siteCode' => 'mats', 'status' => 'awaiting_approval', 'overLimit' => true])
        ->and($juba['number'])->not->toBe('')->and($juba['clientName'])->not->toBe('');

    // Receivables are the unpaid balances, per currency, never summed across currencies.
    // The test builds its own unpaid invoices (one UGX, one USD) so it does not depend on how the seeder leaves invoices.
    $client = (string) DB::table('orders')->where('site_id', $this->mats)->value('client_id');
    foreach ([['UGX', 1500000], ['USD', 400]] as $i => [$cur, $total]) {
        $orderId = (string) Str::uuid7();
        DB::table('invoices')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'order_id' => $orderId, 'order_number' => "CK-{$i}", 'client_id' => $client,
            'number' => "CK-INV-{$i}", 'total' => $total, 'currency' => $cur, 'paid_amount' => 0, 'balance' => $total, 'issued_day' => '2026-11-09', 'issued_at' => '2026-11-09 08:00:00', 'event_id' => (string) Str::uuid7()]);
    }
    $cockpit = collect($this->withToken($this->ceo)->getJson('/api/v1/group/cockpit')->assertOk()->json('data.sites'));
    foreach ($cockpit as $site) {
        $open = DB::table('invoices')->where('site_id', $site['siteId'])->where('balance', '>', 0)->get();
        expect($site['receivables']['count'])->toBe($open->count())
            ->and($site['receivables']['open'])->toEqual((float) $open->where('currency', 'UGX')->sum('balance'));
        foreach ($open->groupBy('currency') as $cur => $rows) {
            expect($site['receivables']['byCurrency'][$cur])->toEqual((float) $rows->sum('balance'));
        }
    }
    expect($cockpit->sum(fn ($s) => $s['receivables']['count']))->toBeGreaterThan(0);

    // The CEO decides an over-limit order on the order; the exception alone is refused, then follows the order.
    $exception = (string) DB::table('exceptions')->where(['kind' => 'credit_over_limit', 'subject_ref' => $juba['id']])->value('id');
    $url = "/api/v1/sites/mats/exceptions/{$exception}/decision";
    $this->withToken($this->ceo)->postJson($url, ['decision' => 'approve'])->assertStatus(422)->assertJsonFragment(['decision' => 'Decide this from the order (approve or hold).']);
    $this->withToken($this->ceo)->postJson($url, ['decision' => 'reject', 'note' => 'No'])->assertStatus(422);
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/orders/{$juba['id']}/hold", ['reason' => 'Overdue balance'])->assertSuccessful();
    expect(DB::table('exceptions')->where('id', $exception)->value('status'))->toBe('held');
    $this->withToken($this->ceo)->postJson("/api/v1/sites/mats/orders/{$juba['id']}/approve")->assertSuccessful();
    expect(DB::table('exceptions')->where('id', $exception)->value('status'))->toBe('approved');
});
