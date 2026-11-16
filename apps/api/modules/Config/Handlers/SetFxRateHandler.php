<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Commands\SetFxRate;
use Tanaad\Config\Models\FxRate;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;

/** Group-wide: one rate per day and currency pair, the latest one wins. */
final class SetFxRateHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof SetFxRate) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $p = new Payload($command->payload);
        $payload = [
            'day' => $p->date('day'),
            'base' => $p->currency('base'),
            'quote' => $p->currency('quote'),
            'rate' => $p->num('rate', null, true),
        ];
        if ($payload['base'] !== '' && $payload['base'] === $payload['quote']) {
            $p->error('quote', 'must differ from base');
        }
        $p->check();
        $id = FxRate::query()->where(['day' => $payload['day'], 'base' => $payload['base'], 'quote' => $payload['quote']])->value('id');

        return [Event::from($command->context(), 'config.fx_rate_set', ['fx_id' => is_string($id) ? $id : (string) Str::uuid7(), ...$payload])];
    }
}
