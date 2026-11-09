<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Tanaad\Config\Commands\DefineNumberSeries;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class DefineNumberSeriesHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof DefineNumberSeries) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        if ($command->context()->siteId === null) {
            throw Rejection::invalidPayload(['site_id' => 'required']);
        }
        $p = new Payload($command->payload);
        $payload = [
            'kind' => $p->str('kind', 32),
            'prefix' => $p->str('prefix', 16),
            'pad' => $p->int('pad', 1, 5),
            'next' => $p->int('next', 1, 1),
        ];
        if ($payload['kind'] !== '' && preg_match('/^[a-z_]+$/', $payload['kind']) !== 1) {
            $p->error('kind', 'lowercase letters and underscores only');
        }
        if ($payload['pad'] > 12) {
            $p->error('pad', 'must be at most 12');
        }
        $p->check();

        return [Event::from($command->context(), 'config.number_series_defined', $payload)];
    }
}
