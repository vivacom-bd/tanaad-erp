<?php

declare(strict_types=1);

namespace Tanaad\Group;

use Illuminate\Support\ServiceProvider;
use Tanaad\Group\Authorization\GroupAuthorizer;
use Tanaad\Group\Console\ContractExpiryCommand;
use Tanaad\Group\Projectors\ExceptionProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class GroupServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ContractExpiryCommand::class]);
        }

        $map = [
            'group.exception_raised' => [Commands\RaiseException::class, Handlers\RaiseExceptionHandler::class, Factories\RaiseExceptionFactory::class],
            'group.brief_sent' => [Commands\SendBrief::class, Handlers\SendBriefHandler::class, Factories\SendBriefFactory::class],
            'group.exception_decided' => [Commands\DecideException::class, Handlers\DecideExceptionHandler::class, Factories\DecideExceptionFactory::class],
        ];
        foreach ($map as $type => [$command, $handler, $factory]) {
            $registry->command($command, $handler, GroupAuthorizer::class)->eventType($type, $factory);
        }
        // Also projects people.engagement_ended; policies react to cash.* and people.* by type string.
        $registry->projectors(ExceptionProjector::class, Projectors\BriefProjector::class);
        $registry->policies(Policies\CashVariancePolicy::class, Policies\GhostWorkerPolicy::class, Policies\ContractExpiringPolicy::class);

        $snapshots->register('exceptions', 'exceptions');
    }
}
