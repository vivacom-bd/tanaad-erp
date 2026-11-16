<?php

declare(strict_types=1);

namespace Tanaad\Production;

use Illuminate\Support\ServiceProvider;
use Tanaad\Identity\Role;
use Tanaad\Production\Authorization\ProductionAuthorizer;
use Tanaad\Production\Projectors\ProductionProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class ProductionServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'production.entry_recorded' => [Commands\RecordProductionEntry::class, Handlers\RecordProductionEntryHandler::class, Factories\RecordProductionEntryFactory::class],
            'production.batch_recorded' => [Commands\RecordBatch::class, Handlers\RecordBatchHandler::class, Factories\RecordBatchFactory::class],
            'production.shift_closed' => [Commands\CloseShift::class, Handlers\CloseShiftHandler::class, Factories\CloseShiftFactory::class],
            'production.waste_weighed' => [Commands\WeighWaste::class, Handlers\WeighWasteHandler::class, Factories\WeighWasteFactory::class],
        ];
        foreach ($map as $type => [$command, $handler, $factory]) {
            $registry->command($command, $handler, ProductionAuthorizer::class)->eventType($type, $factory);
        }
        $registry->projectors(ProductionProjector::class);

        $readers = [Role::Supervisor->value, Role::SiteManager->value];
        $snapshots->register('production_entries', 'production_entries', roles: $readers);
        $snapshots->register('production_batches', 'production_batches', roles: $readers);
        $snapshots->register('production_shifts_closed', 'production_shifts_closed', roles: $readers);
        $snapshots->register('waste_weighings', 'waste_weighings', roles: $readers);
    }
}
