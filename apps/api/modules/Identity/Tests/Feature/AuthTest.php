<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\Device;
use Tanaad\Identity\Models\User;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $this->mats = Site::query()->where('code', 'mats')->firstOrFail();
    $this->admin = User::query()->where('code', 'u_admin')->firstOrFail();
    $this->cashier = User::query()->where('code', 'u_cash_mats')->firstOrFail();
});

function officeToken(User $user): string
{
    return $user->createToken('office', ['user'])->plainTextToken;
}

function registerDevice(Tests\TestCase $t, User $admin, Site $site, string $name = 'Gate tablet'): array
{
    return $t->withToken(officeToken($admin))->postJson("/api/v1/sites/{$site->code}/devices", ['name' => $name])->assertCreated()->json();
}

it('signs an office user in with username and password', function (): void {
    $r = $this->postJson('/api/v1/auth/login', ['username' => 'smmats', 'password' => 'password'])->assertCreated();
    expect($r->json('token'))->toBeString()
        ->and($r->json('user.code'))->toBe('u_sm_mats')
        ->and($r->json('user.roles.0.role'))->toBe('site_manager')
        ->and($r->json('user.roles.0.site_code'))->toBe('mats')
        ->and($r->json('sites'))->toBe([$this->mats->id]);

    $this->withToken($r->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.code', 'u_sm_mats');
});

it('answers a wrong password with problem+json and no token', function (): void {
    $this->postJson('/api/v1/auth/login', ['username' => 'smmats', 'password' => 'nope'])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'forbidden');
    $this->postJson('/api/v1/auth/login', ['username' => ''])->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
});

it('lets a group admin register a device and records the event', function (): void {
    $r = registerDevice($this, $this->admin, $this->mats);
    expect($r['device_token'])->toBeString()->and($r['device']['site_code'])->toBe('mats');
    expect(DB::table('events')->where('type', 'identity.device_registered')->count())->toBe(1)
        ->and(Device::query()->count())->toBe(1);
});

it('forbids a cashier from registering a device', function (): void {
    $this->withToken(officeToken($this->cashier))->postJson('/api/v1/sites/mats/devices', ['name' => 'x'])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->withToken(officeToken($this->cashier))->postJson('/api/v1/sites/bags/devices', ['name' => 'x'])->assertForbidden();
});

it('unlocks a tablet with a PIN and binds the session to the device', function (): void {
    $device = registerDevice($this, $this->admin, $this->mats);
    $r = $this->withToken($device['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])->assertCreated();
    expect($r->json('user.code'))->toBe('u_cash_mats')
        ->and($r->json('device.id'))->toBe($device['device']['id'])
        ->and($r->json('expires_at'))->toBeString();

    $this->withToken($r->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('device.name', 'Gate tablet');
    expect(DB::table('events')->where('type', 'identity.session_opened')->value('device_id'))->toBe($device['device']['id'])
        ->and(Device::query()->findOrFail($device['device']['id'])->last_seen_at)->not->toBeNull();
});

it('refuses a PIN for a user with no role at the device site', function (): void {
    $device = registerDevice($this, $this->admin, $this->mats);
    $this->withToken($device['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_bags', 'pin' => '1234'])
        ->assertForbidden()->assertJsonPath('detail', 'This user has no role at this site.');
});

it('locks the PIN after five wrong attempts', function (): void {
    $device = registerDevice($this, $this->admin, $this->mats);
    for ($i = 0; $i < 5; $i++) {
        $this->withToken($device['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '0000'])->assertForbidden();
    }
    $this->withToken($device['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])
        ->assertForbidden()->assertJsonPath('detail', fn (string $d) => str_contains($d, 'Too many wrong PINs'));
    expect($this->cashier->fresh()->pin_locked_until)->not->toBeNull();
});

it('does not let a user token call the PIN endpoint or a device token call /me', function (): void {
    $this->withToken(officeToken($this->cashier))->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])->assertForbidden();
    $device = registerDevice($this, $this->admin, $this->mats);
    $this->withToken($device['device_token'])->getJson('/api/v1/me')->assertForbidden();
    $this->withoutToken()->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('revokes the current token on sign-out', function (): void {
    $token = officeToken($this->cashier);
    $this->withToken($token)->deleteJson('/api/v1/auth/session')->assertNoContent();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('lets a group admin grant a role and rejects a duplicate grant', function (): void {
    $r = $this->withToken(officeToken($this->admin))->postJson("/api/v1/users/{$this->cashier->id}/roles", ['role' => 'gate', 'site' => 'mats'])->assertCreated();
    expect(collect($r->json('user.roles'))->pluck('role')->all())->toContain('gate');
    $this->withToken(officeToken($this->admin))->postJson("/api/v1/users/{$this->cashier->id}/roles", ['role' => 'gate', 'site' => 'mats'])
        ->assertStatus(409)->assertJsonPath('code', 'duplicate_fact');
    $this->withToken(officeToken($this->admin))->postJson("/api/v1/users/{$this->cashier->id}/roles", ['role' => 'ceo', 'site' => 'mats'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
    $this->withToken(officeToken($this->cashier))->postJson("/api/v1/users/{$this->admin->id}/roles", ['role' => 'gate', 'site' => 'mats'])->assertForbidden();
});

it('treats a repeated Idempotency-Key as a duplicate, not a second grant', function (): void {
    $key = (string) Illuminate\Support\Str::uuid7();
    $this->withToken(officeToken($this->admin))->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/users/{$this->cashier->id}/roles", ['role' => 'driver', 'site' => 'mats'])->assertCreated();
    $this->withToken(officeToken($this->admin))->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/users/{$this->cashier->id}/roles", ['role' => 'driver', 'site' => 'mats'])->assertCreated()->assertJsonPath('event_id', $key);
    expect(DB::table('events')->where('type', 'identity.role_assigned')->where('id', $key)->count())->toBe(1)
        ->and(DB::table('user_site_roles')->where(['user_id' => $this->cashier->id, 'role' => 'driver'])->count())->toBe(1);
});

it('signs in with username and PIN and returns the login shape', function (): void {
    $r = $this->postJson('/api/v1/auth/pin-login', ['username' => 'smmats', 'pin' => '1234'])->assertCreated();
    expect($r->json('token'))->toBeString()->and($r->json('user.code'))->toBe('u_sm_mats')->and($r->json('sites'))->toBe([$this->mats->id]);
    $this->withToken($r->json('token'))->getJson('/api/v1/me')->assertOk();
});

it('answers a wrong PIN or unknown user with the same generic message', function (): void {
    $wrong = $this->postJson('/api/v1/auth/pin-login', ['username' => 'smmats', 'pin' => '9999'])->assertForbidden();
    $unknown = $this->postJson('/api/v1/auth/pin-login', ['username' => 'nobody', 'pin' => '1234'])->assertForbidden();
    expect($wrong->json('detail'))->toBe('Username or PIN is wrong.')->and($unknown->json('detail'))->toBe('Username or PIN is wrong.')
        ->and($wrong->json('token'))->toBeNull();
});

it('throttles PIN sign-in at five attempts a minute per username and IP', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/pin-login', ['username' => 'nobody', 'pin' => '0000'])->assertForbidden();
    }
    $this->postJson('/api/v1/auth/pin-login', ['username' => 'nobody', 'pin' => '0000'])->assertStatus(429);
    $this->postJson('/api/v1/auth/pin-login', ['username' => 'smmats', 'pin' => '1234'])->assertCreated();
});

it('keeps the seeded role grants across a ledger rebuild, without duplicating group roles', function (): void {
    $before = DB::table('user_site_roles')->count();
    expect(DB::table('user_site_roles')->where('seq', '>', 0)->count())->toBe($before);

    app(Tanaad\Shared\Ledger\Replay::class)->rebuild();
    app(Tanaad\Shared\Ledger\Replay::class)->rebuild();
    $this->seed(DemoUsersSeeder::class);   // a rerun is all skips

    expect(DB::table('user_site_roles')->count())->toBe($before)
        ->and(DB::table('user_site_roles')->where(['user_id' => $this->admin->id, 'role' => 'group_admin'])->count())->toBe(1);

    $sup = User::query()->where('code', 'u_sup_mats')->firstOrFail();
    $this->withToken(officeToken($sup))->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('user.roles.0.role', 'supervisor')->assertJsonPath('sites', [$this->mats->id]);
    // A non-ledgered grant (seq 0) survives a rebuild.
    DB::table('user_site_roles')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'user_id' => $sup->id, 'site_id' => $this->mats->id, 'role' => 'cashier', 'seq' => 0]);
    app(Tanaad\Shared\Ledger\Replay::class)->rebuild();
    expect(DB::table('user_site_roles')->where(['user_id' => $sup->id, 'role' => 'cashier'])->exists())->toBeTrue();
});

it('caps PIN sessions at the shorter of the role TTL and pin.session_hours', function (): void {
    config(['tanaad.pin.session_hours' => 2, 'tanaad.auth.token_ttl_minutes.cashier' => 480, 'tanaad.auth.token_ttl_minutes.site_manager' => 1440]);
    Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse('2026-11-10 08:00:00'));
    $device = registerDevice($this, $this->admin, $this->mats);
    $tablet = $this->withToken($device['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])->assertCreated();
    expect(Carbon\CarbonImmutable::parse($tablet->json('expires_at'))->equalTo(Carbon\CarbonImmutable::parse('2026-11-10 10:00:00')))->toBeTrue();
    $this->app['auth']->forgetGuards();
    $this->withToken('')->postJson('/api/v1/auth/pin-login', ['username' => 'smmats', 'pin' => '1234'])->assertCreated();
    expect(Carbon\CarbonImmutable::parse((string) DB::table('personal_access_tokens')->orderByDesc('id')->value('expires_at'))->equalTo(Carbon\CarbonImmutable::parse('2026-11-10 10:00:00')))->toBeTrue();
    config(['tanaad.pin.session_hours' => 100]);
    $this->app['auth']->forgetGuards();
    $long = $this->withToken($device['device_token'])->postJson('/api/v1/auth/pin', ['user_id' => 'u_cash_mats', 'pin' => '1234'])->assertCreated();
    expect(Carbon\CarbonImmutable::parse($long->json('expires_at'))->equalTo(Carbon\CarbonImmutable::parse('2026-11-10 16:00:00')))->toBeTrue();
    Carbon\CarbonImmutable::setTestNow();
});

it('keys the PIN throttle on the forwarded client IP only when the proxy is trusted', function (): void {
    expect(Tanaad\Shared\Http\TrustedProxies::parse(''))->toBeNull()
        ->and(Tanaad\Shared\Http\TrustedProxies::parse(' 10.0.0.0/8, 127.0.0.1 '))->toBe(['10.0.0.0/8', '127.0.0.1'])
        ->and(Tanaad\Shared\Http\TrustedProxies::parse('*'))->toBe('*');
    $from = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(['X-Forwarded-For' => $ip]);
    // Untrusted (the local default): a spoofed header is ignored, so all five count against 127.0.0.1.
    for ($i = 0; $i < 5; $i++) {
        $from('192.0.2.'.$i)->postJson('/api/v1/auth/pin-login', ['username' => 'other', 'pin' => '0000'])->assertForbidden();
    }
    $from('192.0.2.99')->postJson('/api/v1/auth/pin-login', ['username' => 'other', 'pin' => '0000'])->assertStatus(429);

    Illuminate\Http\Middleware\TrustProxies::at(Tanaad\Shared\Http\TrustedProxies::parse('127.0.0.1') ?? []);
    Illuminate\Http\Middleware\TrustProxies::withHeaders(Tanaad\Shared\Http\TrustedProxies::HEADERS);
    try {
        for ($i = 0; $i < 5; $i++) {
            $from('203.0.113.7')->postJson('/api/v1/auth/pin-login', ['username' => 'nobody', 'pin' => '0000'])->assertForbidden();
        }
        $from('203.0.113.7')->postJson('/api/v1/auth/pin-login', ['username' => 'nobody', 'pin' => '0000'])->assertStatus(429);
        $from('198.51.100.9')->postJson('/api/v1/auth/pin-login', ['username' => 'nobody', 'pin' => '0000'])->assertForbidden();
    } finally {
        Illuminate\Http\Middleware\TrustProxies::flushState();
    }
});

it('lists the directory scoped to the caller sites, with roles there and no private fields', function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $token = fn (string $code): string => User::query()->where('code', $code)->firstOrFail()->createToken('office', ['user'])->plainTextToken;

    $r = $this->withToken($token('u_mech'))->getJson('/api/v1/directory')->assertOk()->assertHeader('ETag');
    $by = collect($r->json('data'))->keyBy('code');
    // The mechanic works at mats and recycling: their people, plus group roles; nobody only at bags.
    expect($by->has('u_sup_mats'))->toBeTrue()->and($by->has('u_sup_rec'))->toBeTrue()->and($by->has('u_ceo1'))->toBeTrue()
        ->and($by->has('u_sup_bags'))->toBeFalse()->and($by->has('u_cash_bags'))->toBeFalse()
        ->and(array_keys($by['u_store_mats']))->toBe(['id', 'name', 'code', 'active', 'roles'])
        ->and($by['u_store_mats']['roles'])->toBe([['role' => 'gate', 'siteId' => $by['u_store_mats']['roles'][0]['siteId'], 'siteCode' => 'mats'],
            ['role' => 'storekeeper', 'siteId' => $by['u_store_mats']['roles'][1]['siteId'], 'siteCode' => 'mats']])
        ->and(array_column($by['u_mech']['roles'], 'siteCode'))->toBe(['mats', 'recycling'])
        ->and($by['u_ceo1']['roles'])->toBe([['role' => 'ceo', 'siteId' => null, 'siteCode' => null]]);
    expect(json_encode($r->json()))->not->toContain('pin')->not->toContain('password')->not->toContain('username');

    $this->withToken($token('u_mech'))->withHeader('If-None-Match', (string) $r->headers->get('ETag'))->get('/api/v1/directory')->assertStatus(304);
    expect($this->withToken($token('u_ceo1'))->getJson('/api/v1/directory')->json('data'))->toHaveCount(User::query()->count());
});
