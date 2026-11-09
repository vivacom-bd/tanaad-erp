<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Commands\SaveProduct;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Upserts a product; its prices are replaced by the list in the payload. */
final class SaveProductHandler implements Handler
{
    public const array KINDS = ['mat', 'bag_pack', 'pellet', 'material', 'roll'];

    public const array CHANNELS = ['local', 'export'];

    public function handle(Command $command): array
    {
        if (! $command instanceof SaveProduct) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $siteId = $command->context()->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $productId = $p->uuid('product_id');
        $payload = [
            'product_id' => $productId,
            'code' => $p->optStr('code', 64),
            'internal_name' => $p->str('internal_name'),
            'commercial_name' => $p->optStr('commercial_name'),
            'kind' => $p->oneOf('kind', self::KINDS),
            'bundle_pieces' => $p->int('bundle_pieces', 1),
            'std_bundle_weight_kg' => $p->num('std_bundle_weight_kg'),
            'weight_tolerance_pct' => $p->num('weight_tolerance_pct'),
            'weight_status' => $p->oneOf('weight_status', ['measured', 'to_be_measured']),
            'colour' => $p->optStr('colour', 64),
            'unit' => $p->optStr('unit', 32),
            'thickness_micron' => $p->optInt('thickness_micron', 0),
            'active' => $p->bool('active', true),
            'prices' => [],
        ];
        foreach ($p->children('prices') as $price) {
            $payload['prices'][] = [
                'price_id' => (string) Str::uuid7(),
                'channel' => $price->oneOf('channel', self::CHANNELS),
                'currency' => $price->currency('currency'),
                'amount' => $price->num('amount'),
                'effective_from' => $price->date('effective_from'),
            ];
        }
        $p->check();

        $existing = Product::query()->find($productId);
        if ($existing !== null) {
            if ($existing->site_id !== $siteId) {
                throw Rejection::notFound('Product', $productId);
            }
            $payload['code'] = $existing->code;
        } elseif ($payload['code'] !== null && Product::query()->where('code', $payload['code'])->exists()) {
            throw Rejection::duplicateFact("Code {$payload['code']} is already used.");
        }

        return [Event::from($command->context(), 'config.product_saved', $payload)];
    }
}
