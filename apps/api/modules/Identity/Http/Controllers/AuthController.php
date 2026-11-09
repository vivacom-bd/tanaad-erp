<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Identity\Access;
use Tanaad\Identity\Commands\OpenDeviceSession;
use Tanaad\Identity\Models\Device;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\PinLockout;
use Tanaad\Identity\Role;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Rejection;

final class AuthController extends ApiController
{
    public function __construct(
        private readonly Access $access,
        private readonly Hasher $hasher,
        private readonly PinLockout $pins,
        private readonly CommandBus $bus,
        private readonly Config $config,
    ) {}

    /** Office and CEO sign-in: username + password → bearer token. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:32'], 'password' => ['required', 'string']]);
        $user = User::query()->with('siteRoles.site')->where('username', strtolower(trim($data['username'])))->where('active', true)->first();
        if ($user === null || $user->password === null || ! $this->hasher->check($data['password'], $user->password)) {
            throw Rejection::forbidden('Username or password is wrong.');
        }
        $token = $user->createToken('office', ['user'], now()->addDays(30));

        return new JsonResponse(['token' => $token->plainTextToken, ...$this->session($user, null)], 201);
    }

    /** Shared tablet: a device token plus a user's PIN → short-lived user token bound to the device. */
    public function pin(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();
        $device = $bearer === null ? null : \Laravel\Sanctum\PersonalAccessToken::findToken($bearer)?->tokenable;
        if (! $device instanceof Device || ! $device->active) {
            throw Rejection::forbidden('This endpoint needs a registered device.');
        }
        $data = $request->validate(['user_id' => ['required', 'string'], 'pin' => ['required', 'string', 'min:4', 'max:8']]);
        $user = User::query()->with('siteRoles.site')->where('active', true)
            ->where(fn ($q) => $q->where('code', $data['user_id'])
                ->when(\Illuminate\Support\Str::isUuid($data['user_id']), fn ($q) => $q->orWhere('id', $data['user_id'])))->first();
        if ($user === null) {
            throw Rejection::forbidden('Wrong PIN.');
        }
        $roles = $this->access->rolesAt($user, $device->site_id);
        if ($roles === []) {
            throw Rejection::forbidden('This user has no role at this site.');
        }
        $this->pins->verify($user, $data['pin']);

        $role = Role::from($roles[0]);
        $hours = $role->sessionHours((int) $this->config->get('tanaad.pin.session_hours', 14));
        $token = $user->createToken("device:{$device->id}", ['user', "device:{$device->id}"], now()->addHours($hours));

        $this->bus->dispatch(new OpenDeviceSession(
            new CommandContext($device->site_id, $user->id, $device->id, \Carbon\CarbonImmutable::now()),
            $user->id,
            $role->value,
        ));

        return new JsonResponse(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at?->toIso8601String(), ...$this->session($user, $device)], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();
        if ($bearer !== null) {
            \Laravel\Sanctum\PersonalAccessToken::findToken($bearer)?->delete();
        }

        return new JsonResponse(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('This token belongs to a device, not a user.');
        }
        $user->load('siteRoles.site');
        $device = null;
        $token = $user->currentAccessToken();
        if (str_starts_with((string) $token->name, 'device:')) {
            $device = Device::query()->find(substr((string) $token->name, 7));
        }

        return new JsonResponse($this->session($user, $device));
    }

    /** @return array<string, mixed> */
    private function session(User $user, ?Device $device): array
    {
        $siteIds = $this->access->siteIds($user);

        return [
            'user' => $user->toProfile(),
            'sites' => $siteIds,       // null = every site
            'device' => $device === null ? null : ['id' => $device->id, 'name' => $device->name, 'site_id' => $device->site_id, 'site_code' => $device->site->code],
        ];
    }
}
