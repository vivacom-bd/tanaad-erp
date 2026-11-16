<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Commands\SetBenchmark;
use Tanaad\Config\Models\Benchmark;
use Tanaad\Config\Models\Machine;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class SetBenchmarkHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof SetBenchmark) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $siteId = $command->context()->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $machineId = $p->uuid('machine_id');
        $productId = $p->uuid('product_id');
        $units = $p->num('units_per_shift');
        $p->check();

        if (! Machine::query()->where(['id' => $machineId, 'site_id' => $siteId])->exists()) {
            throw Rejection::notFound('Machine', $machineId);
        }
        if (! Product::query()->where(['id' => $productId, 'site_id' => $siteId])->exists()) {
            throw Rejection::notFound('Product', $productId);
        }
        $id = Benchmark::query()->where(['machine_id' => $machineId, 'product_id' => $productId])->value('id');

        return [Event::from($command->context(), 'config.benchmark_set', [
            'benchmark_id' => is_string($id) ? $id : (string) Str::uuid7(),
            'machine_id' => $machineId,
            'product_id' => $productId,
            'units_per_shift' => $units,
        ])];
    }
}
