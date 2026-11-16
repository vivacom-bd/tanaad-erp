<?php

declare(strict_types=1);

namespace Tanaad\Stock;

use Illuminate\Support\ServiceProvider;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Stock\Authorization\StockAuthorizer;
use Tanaad\Stock\Projectors\StockProjector;
use Tanaad\Sync\Snapshots;

final class StockServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'stock.received' => [Commands\ReceiveStock::class, Handlers\ReceiveStockHandler::class, [Factories\ReceiveStockFactory::class]],
            'stock.label_moved' => [Commands\MoveStock::class, Handlers\MoveStockHandler::class, [Factories\MoveStockFactory::class]],
            'stock.label_scrapped' => [Commands\ScrapLabel::class, Handlers\ScrapLabelHandler::class, [Factories\ScrapLabelFactory::class]],
            'stock.transfer_sent' => [Commands\SendTransfer::class, Handlers\SendTransferHandler::class, [Factories\SendTransferFactory::class]],
            'stock.transfer_received' => [Commands\ReceiveTransfer::class, Handlers\ReceiveTransferHandler::class, [Factories\ReceiveTransferFactory::class]],
            'stock.counted' => [Commands\RecordStockCount::class, Handlers\RecordStockCountHandler::class, [Factories\RecordStockCountFactory::class]],
            'stock.count_reopened' => [Commands\ReopenStockCount::class, Handlers\ReopenStockCountHandler::class, [Factories\ReopenStockCountFactory::class]],
        ];
        foreach ($map as $type => [$command, $handler, [$factory]]) {
            $entry = $registry->command($command, $handler, StockAuthorizer::class)->eventType($type, $factory);
            if ($type === 'stock.label_moved') {
                // A move without a label (zone to zone by quantity, or an adjustment) arrives under these types.
                $entry->eventType('stock.moved', $factory)->eventType('stock.adjusted', $factory);
            }
        }
        // Also projects labels.created / labels.consumed / dispatch.label_dispatched, by type string only.
        $registry->projectors(StockProjector::class);

        $snapshots->register('stock_on_hand', 'stock_on_hand', roles: [Role::Storekeeper->value, Role::Supervisor->value, Role::SiteManager->value]);
    }
}
