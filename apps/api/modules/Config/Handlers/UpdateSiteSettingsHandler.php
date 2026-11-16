<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Tanaad\Config\Commands\UpdateSiteSettings;
use Tanaad\Config\Models\SiteSetting;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class UpdateSiteSettingsHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof UpdateSiteSettings) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        if ($command->context()->siteId === null) {
            throw Rejection::invalidPayload(['site_id' => 'required']);
        }
        $p = new Payload($command->payload);
        $payload = [];
        foreach (SiteSetting::FIELDS as $column) {
            $payload[$column] = $p->num($column, 0.0);
        }
        $p->check();

        return [Event::from($command->context(), 'config.site_settings_changed', $payload)];
    }
}
