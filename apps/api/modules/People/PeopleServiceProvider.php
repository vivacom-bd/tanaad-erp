<?php

declare(strict_types=1);

namespace Tanaad\People;

use Illuminate\Support\ServiceProvider;
use Tanaad\People\Authorization\PeopleAuthorizer;
use Tanaad\People\Console\ArchiveAbsentCommand;
use Tanaad\People\Factories\PeopleEnvelopeFactory;
use Tanaad\People\Projectors\PeopleProjector;
use Tanaad\People\Projectors\SettlementProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class PeopleServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ArchiveAbsentCommand::class]);
        }

        $map = [
            Commands\RegisterPerson::class => [Handlers\RegisterPersonHandler::class, ['people.person_registered']],
            Commands\StartEngagement::class => [Handlers\StartEngagementHandler::class, ['people.engagement_started']],
            Commands\RenewEngagement::class => [Handlers\RenewEngagementHandler::class, ['people.engagement_renewed']],
            Commands\EndEngagement::class => [Handlers\EndEngagementHandler::class, ['people.engagement_ended']],
            Commands\MarkAttendance::class => [Handlers\MarkAttendanceHandler::class, ['people.attendance_marked', 'people.attendance_corrected']],
            Commands\ArchivePerson::class => [Handlers\ArchivePersonHandler::class, ['people.person_archived']],
            Commands\ReactivatePerson::class => [Handlers\ReactivatePersonHandler::class, ['people.person_reactivated']],
            Commands\CreateSettlementRun::class => [Handlers\CreateSettlementRunHandler::class, ['people.settlement_run_created']],
            Commands\ApproveSettlement::class => [Handlers\ApproveSettlementHandler::class, ['people.settlement_approved']],
            Commands\CancelSettlementRun::class => [Handlers\CancelSettlementRunHandler::class, ['people.settlement_cancelled']],
            Commands\RecordPayout::class => [Handlers\RecordPayoutHandler::class, ['people.payout_recorded']],
            Commands\GiveAdvance::class => [Handlers\GiveAdvanceHandler::class, ['people.advance_given']],
        ];
        foreach ($map as $command => [$handler, $types]) {
            $registry->command($command, $handler, PeopleAuthorizer::class);
            foreach ($types as $type) {
                $registry->eventType($type, PeopleEnvelopeFactory::class);
            }
        }
        $registry->projectors(PeopleProjector::class, SettlementProjector::class);

        foreach (['people', 'engagements', 'attendance', 'settlement_runs', 'settlement_lines', 'payouts', 'advances'] as $table) {
            $snapshots->register($table, $table);
        }
    }
}
