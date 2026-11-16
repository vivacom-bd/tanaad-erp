<?php

declare(strict_types=1);

namespace Tanaad\Spares;

use Illuminate\Support\ServiceProvider;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Spares\Authorization\SparesAuthorizer;
use Tanaad\Spares\Projectors\SparesProjector;
use Tanaad\Sync\Snapshots;

final class SparesServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'spares.part_saved' => [Commands\SavePart::class, Handlers\SavePartHandler::class],
            'spares.part_received' => [Commands\ReceivePart::class, Handlers\ReceivePartHandler::class],
            'spares.part_issued' => [Commands\IssuePart::class, Handlers\IssuePartHandler::class],
            'spares.breakdown_reported' => [Commands\ReportBreakdown::class, Handlers\ReportBreakdownHandler::class],
            'spares.breakdown_resolved' => [Commands\ResolveBreakdown::class, Handlers\ResolveBreakdownHandler::class],
        ];
        foreach ($map as $type => [$command, $handler]) {
            $registry->command($command, $handler, SparesAuthorizer::class)->eventType($type, Factories\SparesEnvelopeFactory::class);
        }
        // Group's LowStockPolicy reacts to spares.part_issued, and its ExceptionProjector settles low_stock on
        // spares.part_received / spares.part_saved, by type string.
        $registry->projectors(SparesProjector::class);

        $store = SparesAuthorizer::readRoles(false);
        $snapshots->register('parts', 'parts', roles: $store);
        $snapshots->register('part_issues', 'part_issues', roles: $store);
        $snapshots->register('breakdowns', 'breakdowns', roles: SparesAuthorizer::readRoles(true));
    }
}
