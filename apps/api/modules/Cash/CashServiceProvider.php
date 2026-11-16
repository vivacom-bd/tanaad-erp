<?php

declare(strict_types=1);

namespace Tanaad\Cash;

use Illuminate\Support\ServiceProvider;
use Tanaad\Cash\Authorization\CashAuthorizer;
use Tanaad\Cash\Projectors\CashProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class CashServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'cash.entry_recorded' => [Commands\RecordCashEntry::class, Handlers\RecordCashEntryHandler::class, Factories\RecordCashEntryFactory::class],
            'cash.entry_corrected' => [Commands\CorrectCashEntry::class, Handlers\CorrectCashEntryHandler::class, Factories\CorrectCashEntryFactory::class],
            'cash.day_closed' => [Commands\CloseCashDay::class, Handlers\CloseCashDayHandler::class, Factories\CloseCashDayFactory::class],
            'cash.day_reopened' => [Commands\ReopenCashDay::class, Handlers\ReopenCashDayHandler::class, Factories\ReopenCashDayFactory::class],
            'cash.opening_set' => [Commands\SetCashOpening::class, Handlers\SetCashOpeningHandler::class, Factories\SetCashOpeningFactory::class],
        ];
        foreach ($map as $type => [$command, $handler, $factory]) {
            $registry->command($command, $handler, CashAuthorizer::class)->eventType($type, $factory);
        }
        // Also projects people.payout_recorded (cash payouts), subscribed by type string.
        $registry->projectors(CashProjector::class);

        // Same read roles as GET /cash/days/{day} (CashAuthorizer::canRead): cashier, site manager, group roles.
        $readers = CashAuthorizer::READ_ROLES;
        $snapshots->register('cash_days', 'cash_days', roles: $readers);
        $snapshots->register('cash_entries', 'cash_entries', roles: $readers);
    }
}
