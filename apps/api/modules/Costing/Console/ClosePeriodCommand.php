<?php

declare(strict_types=1);

namespace Tanaad\Costing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Costing\Commands\ClosePeriod;
use Tanaad\Costing\Support\CostBook;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Monthly: closes the previous calendar month (or --month) for every site (or --site=<code>) as the
 * system. A period already closed is reported and skipped, so re-running is harmless.
 */
final class ClosePeriodCommand extends Command
{
    protected $signature = 'costing:close-period {--month= : YYYY-MM, default the previous month} {--site= : site code, default all}';

    protected $description = 'Freeze the P&L and unit costs of a month from its cash-closed days';

    public function handle(CommandBus $bus, ConnectionInterface $db): int
    {
        $month = $this->option('month');
        $month = is_string($month) && $month !== '' ? $month : CarbonImmutable::now()->setTimezone(CostBook::timezone())->startOfMonth()->subMonth()->format('Y-m');
        $sites = $db->table('sites')->orderBy('code');
        $code = $this->option('site');
        if (is_string($code) && $code !== '') {
            $sites->where('code', $code);
        }
        $closed = 0;
        foreach ($sites->get(['id', 'code']) as $site) {
            try {
                $bus->dispatch(new ClosePeriod(CommandContext::system((string) $site->id), ['month' => $month]));
                $closed++;
            } catch (Rejection $r) {
                $this->warn("{$site->code}: {$r->getMessage()}");
            }
        }
        $this->info("Closed {$closed}.");

        return self::SUCCESS;
    }
}
