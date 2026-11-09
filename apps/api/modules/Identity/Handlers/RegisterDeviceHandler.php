<?php

declare(strict_types=1);

namespace Tanaad\Identity\Handlers;

use Tanaad\Identity\Commands\RegisterDevice;
use Tanaad\Identity\Models\Device;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class RegisterDeviceHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof RegisterDevice) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        if ($command->context()->siteId === null) {
            throw Rejection::invalidPayload(['site' => 'A device belongs to one site.']);
        }
        if (trim($command->name) === '') {
            throw Rejection::invalidPayload(['name' => 'required']);
        }
        if (Device::query()->whereKey($command->deviceId)->exists()) {
            throw Rejection::duplicateFact('This device is already registered.');
        }

        return [Event::from($command->context(), 'identity.device_registered', [
            'device_id' => $command->deviceId,
            'name' => $command->name,
            'kind' => $command->kind,
        ])];
    }
}
