<?php

declare(strict_types=1);

namespace Tanaad\Group\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Support\ContractExpiry;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Daily: active engagements ending within their site's contract_warning_days, or already
 * past their end date, raise contract_expiring through the CommandBus as the system.
 * Re-running is harmless: RaiseException is idempotent per engagement while one is open.
 * Reads People's engagements table by name only.
 */
final class ContractExpiryCommand extends Command
{
    protected $signature = 'people:contract-expiry';

    protected $description = 'Raise contract_expiring exceptions for contracts ending within the site warning window';

    public function handle(CommandBus $bus, ConnectionInterface $db, ExceptionRules $rules): int
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($rules->timezone())->toDateString();
        $raised = 0;
        $engagements = $db->table('engagements')->where('status', 'active')->whereNotNull('end_date')->whereNull('deleted_at')
            ->orderBy('end_date')->get(['id', 'site_id', 'person_id', 'end_date']);
        $windows = [];
        foreach ($engagements as $e) {
            $site = (string) $e->site_id;
            $windows[$site] ??= $rules->contractWarningDays($site);
            $command = ContractExpiry::raise($site, (string) $e->id, (string) $e->person_id, (string) $e->end_date, $today, $windows[$site], $db, $now);
            if ($command === null) {
                continue;
            }
            try {
                $raised += $bus->dispatch($command)->events === [] ? 0 : 1;
            } catch (Rejection $r) {
                $this->warn("{$e->id}: {$r->getMessage()}");
            }
        }
        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }
}
