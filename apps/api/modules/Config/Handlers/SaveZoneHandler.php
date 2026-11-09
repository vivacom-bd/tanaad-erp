<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Tanaad\Config\Commands\SaveZone;
use Tanaad\Config\Models\Zone;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class SaveZoneHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof SaveZone) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $siteId = $command->context()->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $zoneId = $p->uuid('zone_id');
        $name = $p->str('name', 120);
        $p->check();

        $existing = Zone::query()->find($zoneId);
        if ($existing !== null && $existing->site_id !== $siteId) {
            throw Rejection::notFound('Zone', $zoneId);
        }

        return [Event::from($command->context(), 'config.zone_saved', ['zone_id' => $zoneId, 'name' => $name])];
    }
}
