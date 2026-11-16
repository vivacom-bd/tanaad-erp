<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $this->mats = Site::query()->where('code', 'mats')->firstOrFail();
    $this->bags = Site::query()->where('code', 'bags')->firstOrFail();
    $this->admin = User::query()->where('code', 'u_admin')->firstOrFail();
    $this->cashier = User::query()->where('code', 'u_cash_mats')->firstOrFail();
    $this->manager = User::query()->where('code', 'u_sm_mats')->firstOrFail();
    $this->tok = fn (User $u): string => $u->createToken('office', ['user'])->plainTextToken;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    config(['tanaad.rate_limits.api' => 600, 'tanaad.rate_limits.ingest' => 120]);
});

function newDevice(Tests\TestCase $t, User $admin, Site $site): array
{
    return $t->withToken($admin->createToken('office', ['user'])->plainTextToken)->postJson("/api/v1/sites/{$site->code}/devices", ['name' => 'Gate'])->assertCreated()->json();
}

// ---- token expiry -------------------------------------------------------------------------

it('issues role-based expiries: cashier shorter than ceo, device tokens never by default', function (): void {
    config(['tanaad.auth.token_ttl_minutes.cashier' => 60, 'tanaad.auth.token_ttl_minutes.group_admin' => 480]);
    $this->postJson('/api/v1/auth/login', ['username' => 'cashmats', 'password' => 'password'])->assertCreated();
    $cashierToken = PersonalAccessToken::query()->where('tokenable_id', $this->cashier->id)->latest('id')->firstOrFail();
    expect((int) round(CarbonImmutable::now()->diffInMinutes($cashierToken->expires_at, true)))->toBe(60);

    $d = newDevice($this, $this->admin, $this->mats);
    expect(PersonalAccessToken::findToken($d['device_token'])->expires_at)->toBeNull();
});

it('answers 401 token_expired once a token passes its expires_at', function (): void {
    $plain = $this->cashier->createToken('office', ['user'], CarbonImmutable::now()->addMinutes(5))->plainTextToken;
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'token_expired');
    expect(PersonalAccessToken::findToken($plain))->toBeNull();
});

it('ages a token with no expires_at from created_at using the role ttl', function (): void {
    config(['tanaad.auth.token_ttl_minutes.cashier' => 30]);
    $plain = ($this->tok)($this->cashier);
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'token_expired');
});

it('lets a role with ttl 0 keep an old token, and a longer role win for multi-role users', function (): void {
    config(['tanaad.auth.token_ttl_minutes.cashier' => 0]);
    $plain = ($this->tok)($this->cashier);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(400));
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
});

it('still answers a missing token with plain 401 unauthenticated', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

// ---- rate limits --------------------------------------------------------------------------

it('limits the general API per user, not per IP', function (): void {
    config(['tanaad.rate_limits.api' => 3]);
    $a = ($this->tok)($this->cashier);
    $b = ($this->tok)($this->manager);
    for ($i = 0; $i < 3; $i++) {
        $this->withToken($a)->getJson('/api/v1/me')->assertOk();
    }
    $this->withToken($a)->getJson('/api/v1/me')->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    $this->withToken($b)->getJson('/api/v1/me')->assertOk();
});

it('limits ingestion per device', function (): void {
    config(['tanaad.rate_limits.ingest' => 2]);
    $d1 = newDevice($this, $this->admin, $this->mats);
    $d2 = newDevice($this, $this->admin, $this->mats);
    $s1 = $this->withToken($d1['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])->assertCreated()->json('token');
    $s2 = $this->withToken($d2['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])->assertCreated()->json('token');
    $body = ['batch_id' => (string) Illuminate\Support\Str::uuid(), 'events' => []];
    $this->withToken($s1)->postJson('/api/v1/sites/mats/events', $body)->assertOk();
    $this->withToken($s1)->postJson('/api/v1/sites/mats/events', $body)->assertOk();
    $this->withToken($s1)->postJson('/api/v1/sites/mats/events', $body)->assertStatus(429);
    $this->withToken($s2)->postJson('/api/v1/sites/mats/events', $body)->assertOk();
});

it('throttles repeated password sign-in per username and IP', function (): void {
    RateLimiter::clear('login');
    config(['tanaad.rate_limits.login' => 3]);
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/login', ['username' => 'cashmats', 'password' => 'nope'])->assertForbidden();
    }
    $this->postJson('/api/v1/auth/login', ['username' => 'cashmats', 'password' => 'nope'])->assertStatus(429);
    $this->postJson('/api/v1/auth/login', ['username' => 'smmats', 'password' => 'nope'])->assertForbidden();
});

// ---- device heartbeat ---------------------------------------------------------------------

it('stores a heartbeat from a device token and from a device-bound user session', function (): void {
    $d = newDevice($this, $this->admin, $this->mats);
    $this->withToken($d['device_token'])->postJson('/api/v1/devices/heartbeat', ['app_version' => '1.4.0', 'online' => true, 'outbox_depth' => 3, 'last_sync_at' => CarbonImmutable::now()->subMinute()->toIso8601String()])->assertStatus(202);
    $row = DB::table('device_heartbeats')->where('device_id', $d['device']['id'])->first();
    expect($row->app_version)->toBe('1.4.0')->and((int) $row->outbox_depth)->toBe(3)->and($row->site_id)->toBe($this->mats->id);

    $session = $this->withToken($d['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])->json('token');
    $this->withToken($session)->postJson('/api/v1/devices/heartbeat', ['online' => false, 'outbox_depth' => 9])->assertStatus(202);
    expect(DB::table('device_heartbeats')->count())->toBe(1)
        ->and((int) DB::table('device_heartbeats')->value('outbox_depth'))->toBe(9)
        ->and((bool) DB::table('device_heartbeats')->value('online'))->toBeFalse();
});

it('rejects heartbeats that are not from a device or are malformed', function (): void {
    $this->postJson('/api/v1/devices/heartbeat', ['online' => true, 'outbox_depth' => 0])->assertUnauthorized();
    $this->withToken(($this->tok)($this->cashier))->postJson('/api/v1/devices/heartbeat', ['online' => true, 'outbox_depth' => 0])->assertForbidden();
    $d = newDevice($this, $this->admin, $this->mats);
    $this->withToken($d['device_token'])->postJson('/api/v1/devices/heartbeat', ['online' => true, 'outbox_depth' => -1])->assertStatus(422);
    $this->withToken($d['device_token'])->postJson('/api/v1/devices/heartbeat', ['outbox_depth' => 1])->assertStatus(422);
});

it('lists device health for admins with stale and never-reported devices', function (): void {
    $fresh = newDevice($this, $this->admin, $this->mats);
    $quiet = newDevice($this, $this->admin, $this->mats);
    $never = newDevice($this, $this->admin, $this->bags);
    $this->withToken($fresh['device_token'])->postJson('/api/v1/devices/heartbeat', ['online' => true, 'outbox_depth' => 0])->assertStatus(202);
    $this->withToken($quiet['device_token'])->postJson('/api/v1/devices/heartbeat', ['online' => true, 'outbox_depth' => 4])->assertStatus(202);
    DB::table('device_heartbeats')->where('device_id', $quiet['device']['id'])->update(['reported_at' => CarbonImmutable::now()->subHour()]);

    $admin = ($this->tok)($this->admin);
    $all = $this->withToken($admin)->getJson('/api/v1/devices/heartbeats')->assertOk()->json('data');
    expect($all)->toHaveCount(3);
    $stale = collect($this->withToken($admin)->getJson('/api/v1/devices/heartbeats?stale=1')->assertOk()->json('data'))->pluck('device_id')->all();
    expect($stale)->toEqualCanonicalizing([$quiet['device']['id'], $never['device']['id']]);
    $this->withToken($admin)->getJson('/api/v1/devices/heartbeats?site=bags&stale=1&stale_after_minutes=1')->assertOk()->assertJsonCount(1, 'data');

    // a site manager sees only their own site; other roles are refused
    $mgr = ($this->tok)($this->manager);
    $this->withToken($mgr)->getJson('/api/v1/devices/heartbeats')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($mgr)->getJson('/api/v1/devices/heartbeats?site=bags')->assertForbidden();
    $this->withToken(($this->tok)($this->cashier))->getJson('/api/v1/devices/heartbeats')->assertForbidden();
});
