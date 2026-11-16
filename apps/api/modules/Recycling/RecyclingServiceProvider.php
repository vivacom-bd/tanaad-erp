<?php

declare(strict_types=1);

namespace Tanaad\Recycling;

use Illuminate\Support\ServiceProvider;
use Tanaad\Identity\Role;
use Tanaad\Recycling\Authorization\RecyclingAuthorizer;
use Tanaad\Recycling\Projectors\RecyclingProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class RecyclingServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'recycling.collector_intake_recorded' => [Commands\RecordCollectorIntake::class, Handlers\RecordCollectorIntakeHandler::class],
            'recycling.collector_paid' => [Commands\PayCollector::class, Handlers\PayCollectorHandler::class],
        ];
        foreach ($map as $type => [$command, $handler]) {
            $registry->command($command, $handler, RecyclingAuthorizer::class)->eventType($type, Factories\RecyclingEnvelopeFactory::class);
        }
        // recycling.collector_paid is also booked by the Cash module as a materials cash-out (by type string).
        $registry->projectors(RecyclingProjector::class);

        $snapshots->register('collector_deliveries', 'collector_deliveries', roles: [Role::Supervisor->value, Role::SiteManager->value, Role::Gate->value, Role::Storekeeper->value, Role::Cashier->value]);
    }
}
