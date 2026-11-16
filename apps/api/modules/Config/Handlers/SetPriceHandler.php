<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Commands\SetPrice;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Adds one price row; earlier rows stay as history. */
final class SetPriceHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof SetPrice) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $siteId = $command->context()->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $payload = [
            'price_id' => (string) Str::uuid7(),
            'product_id' => $p->uuid('product_id'),
            'channel' => $p->oneOf('channel', SaveProductHandler::CHANNELS),
            'currency' => $p->currency('currency'),
            'amount' => $p->num('amount'),
            'effective_from' => $p->date('effective_from'),
        ];
        $p->check();
        if (! Product::query()->where(['id' => $payload['product_id'], 'site_id' => $siteId])->exists()) {
            throw Rejection::notFound('Product', $payload['product_id']);
        }

        return [Event::from($command->context(), 'config.price_set', $payload)];
    }
}
