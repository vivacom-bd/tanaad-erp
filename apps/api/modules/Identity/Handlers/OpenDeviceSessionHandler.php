<?php

declare(strict_types=1);

namespace Tanaad\Identity\Handlers;

use Tanaad\Identity\Commands\OpenDeviceSession;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;

final class OpenDeviceSessionHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof OpenDeviceSession) {
            throw new \LogicException('Wrong command for '.self::class);
        }

        return [Event::from($command->context(), 'identity.session_opened', [
            'user_id' => $command->userId,
            'role' => $command->role,
            'device_id' => $command->context()->deviceId,
        ])];
    }
}
