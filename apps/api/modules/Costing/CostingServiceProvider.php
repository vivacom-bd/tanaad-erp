<?php

declare(strict_types=1);

namespace Tanaad\Costing;

use Illuminate\Support\ServiceProvider;
use Tanaad\Costing\Authorization\CostingAuthorizer;
use Tanaad\Costing\Console\ClosePeriodCommand;
use Tanaad\Costing\Projectors\CostingProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class CostingServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ClosePeriodCommand::class]);
        }

        $map = [
            'costing.period_closed' => [Commands\ClosePeriod::class, Handlers\ClosePeriodHandler::class, Factories\ClosePeriodFactory::class],
            'costing.period_reopened' => [Commands\ReopenPeriod::class, Handlers\ReopenPeriodHandler::class, Factories\ReopenPeriodFactory::class],
            'costing.mapping_saved' => [Commands\SaveCostMapping::class, Handlers\SaveCostMappingHandler::class, Factories\SaveCostMappingFactory::class],
        ];
        foreach ($map as $type => [$command, $handler, $factory]) {
            $registry->command($command, $handler, CostingAuthorizer::class)->eventType($type, $factory);
        }
        // Also projects cash.*, people.payout_recorded/advance_given, recycling.collector_paid, spares.part_issued,
        // purchases.received, production.entry_recorded and orders.invoiced, by type string only.
        $registry->projectors(CostingProjector::class);

        $snapshots->register('costing_periods', 'costing_periods', roles: ['site_manager']);
        $snapshots->register('cost_category_map', 'cost_category_map', roles: ['site_manager']);
    }
}
