<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\Device;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Queries\DeviceHealth;
use Tanaad\Identity\Role;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Rejection;

/** Device health: tablets report in; admins see who has gone quiet. */
final class HeartbeatController extends ApiController
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly DeviceHealth $health,
        private readonly Config $config,
    ) {}

    /** POST /devices/heartbeat — the device is the one the token belongs to; its id is never taken from the body. */
    public function store(Request $request): JsonResponse
    {
        $device = $this->deviceOf($request);
        $data = $request->validate([
            'app_version' => ['nullable', 'string', 'max:40'],
            'online' => ['required', 'boolean'],
            'outbox_depth' => ['required', 'integer', 'min:0', 'max:10000000'],
            'last_sync_at' => ['nullable', 'date'],
        ]);
        $now = CarbonImmutable::now();
        $lastSync = isset($data['last_sync_at']) ? CarbonImmutable::parse($data['last_sync_at']) : null;
        if ($lastSync !== null && $lastSync->isAfter($now->addMinutes(5))) {
            throw Rejection::invalidPayload(['last_sync_at' => 'cannot be in the future']);
        }
        $this->db->table('device_heartbeats')->upsert(
            [[
                'device_id' => $device->id,
                'site_id' => $device->site_id,
                'app_version' => $data['app_version'] ?? null,
                'online' => (bool) $data['online'],
                'outbox_depth' => (int) $data['outbox_depth'],
                'last_sync_at' => $lastSync,
                'reported_at' => $now,
            ]],
            ['device_id'],
            ['site_id', 'app_version', 'online', 'outbox_depth', 'last_sync_at', 'reported_at'],
        );

        return new JsonResponse(['ok' => true, 'device_id' => $device->id, 'reported_at' => $now->toIso8601String()], 202);
    }

    /**
     * GET /devices/heartbeats?site=<code|id>&stale=1&stale_after_minutes=15
     * Group roles see every site; a site manager sees their own sites. Everyone else: 403.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('This token belongs to a device, not a user.');
        }
        $user->loadMissing('siteRoles');
        if (! $this->access->hasGroupRole($user) && ! $user->siteRoles->contains(fn ($r): bool => $r->role === Role::SiteManager->value)) {
            throw Rejection::forbidden('Only group roles and site managers may see device health.');
        }
        $allowed = $this->access->siteIds($user);
        if (! $this->access->hasGroupRole($user)) {
            $allowed = array_values($user->siteRoles->filter(fn ($r): bool => $r->role === Role::SiteManager->value && $r->site_id !== null)
                ->pluck('site_id')->map(fn ($id): string => (string) $id)->unique()->all());
        }

        $siteId = null;
        $code = $request->query('site');
        if (is_string($code) && $code !== '') {
            $site = Site::query()->where('code', $code)->orWhere('id', $code)->first();
            if ($site === null) {
                throw Rejection::notFound('Site', $code);
            }
            if ($allowed !== null && ! in_array($site->id, $allowed, true)) {
                throw Rejection::forbidden("You have no access to {$site->code}.");
            }
            $siteId = $site->id;
        }
        $minutes = (int) $request->query('stale_after_minutes', (int) $this->config->get('tanaad.devices.stale_after_minutes', 15));
        $rows = $this->health->list($siteId === null ? $allowed : [$siteId], max(1, $minutes), $request->boolean('stale'));

        return new JsonResponse(['stale_after_minutes' => max(1, $minutes), 'data' => $rows]);
    }

    private function deviceOf(Request $request): Device
    {
        $token = $request->user()?->currentAccessToken();
        $device = $token?->tokenable;
        if (! $device instanceof Device) {
            $name = (string) ($token->name ?? '');
            $device = str_starts_with($name, 'device:') ? Device::query()->find(substr($name, 7)) : null;
        }
        if (! $device instanceof Device || ! $device->active) {
            throw Rejection::forbidden('Heartbeats come from a registered device.');
        }

        return $device;
    }
}
