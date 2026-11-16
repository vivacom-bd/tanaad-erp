<?php

declare(strict_types=1);

namespace Tanaad\Purchases;

use Illuminate\Support\ServiceProvider;
use Tanaad\Purchases\Authorization\PurchasesAuthorizer;
use Tanaad\Purchases\Projectors\PurchasesProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class PurchasesServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'purchases.recorded' => [Commands\RecordPurchase::class, Handlers\RecordPurchaseHandler::class],
            'purchases.changed' => [Commands\ChangePurchase::class, Handlers\ChangePurchaseHandler::class],
            'purchases.received' => [Commands\ReceivePurchase::class, Handlers\ReceivePurchaseHandler::class],
        ];
        foreach ($map as $type => [$command, $handler]) {
            $registry->command($command, $handler, PurchasesAuthorizer::class)->eventType($type, Factories\PurchasesEnvelopeFactory::class);
        }
        $registry->projectors(PurchasesProjector::class);

        $snapshots->register('purchases', 'purchases', roles: PurchasesAuthorizer::readRoles());
    }
}
