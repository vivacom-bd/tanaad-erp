<?php

declare(strict_types=1);

namespace Tanaad\Labels;

use Illuminate\Support\ServiceProvider;
use Tanaad\Identity\Role;
use Tanaad\Labels\Authorization\LabelsAuthorizer;
use Tanaad\Labels\Projectors\LabelsProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class LabelsServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'labels.created' => [Commands\CreateLabel::class, Handlers\CreateLabelHandler::class, Factories\CreateLabelFactory::class],
            'labels.consumed' => [Commands\ConsumeLabel::class, Handlers\ConsumeLabelHandler::class, Factories\ConsumeLabelFactory::class],
        ];
        foreach ($map as $type => [$command, $handler, $factory]) {
            $registry->command($command, $handler, LabelsAuthorizer::class)->eventType($type, $factory);
        }
        // Also appends stock.* / dispatch.* label events to the history, subscribed by type string.
        $registry->projectors(LabelsProjector::class);

        $snapshots->register('labels', 'labels', roles: [Role::Supervisor->value, Role::Storekeeper->value, Role::SiteManager->value]);
    }
}
