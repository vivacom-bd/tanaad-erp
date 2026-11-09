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
    expect(DB::table('events')->where('type', 'identity.role_assigned')->count())->toBe(1);
});
