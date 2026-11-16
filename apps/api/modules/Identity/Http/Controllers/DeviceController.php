<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tanaad\Identity\Commands\RegisterDevice;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\Device;
use Tanaad\Identity\TokenTtl;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;

final class DeviceController extends ApiController
{
    public function __construct(private readonly CommandBus $bus, private readonly TokenTtl $ttl) {}

    /** Register a shared tablet at a site. The device token is shown once. */
    public function store(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'kind' => ['sometimes', 'string', 'max:32']]);
        $deviceId = (string) Str::uuid7();

        $result = $this->bus->dispatch(new RegisterDevice($this->context($request, $site->id), $deviceId, $data['name'], $data['kind'] ?? 'tablet'));

        $device = Device::query()->findOrFail($deviceId);
        $token = $device->createToken('device', ['device'], $this->ttl->expiryForDevice());

        return $this->created([
            'device' => ['id' => $device->id, 'name' => $device->name, 'kind' => $device->kind, 'site_id' => $device->site_id, 'site_code' => $site->code],
            'device_token' => $token->plainTextToken,
            'event_id' => $result->primary()?->id(),
        ]);
    }
}
