<?php

declare(strict_types=1);

namespace Tanaad\Group\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Tanaad\Group\Commands\SendBrief;
use Tanaad\Group\Models\Brief;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Group\Support\MorningBrief;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Daily 06:30: summarise yesterday per site and record it as group.brief_sent under the system
 * context. One brief per date: the brief id (and event id) derive from the date, so a re-run
 * (or a second scheduler) sends nothing new. The messaging module delivers on the event.
 */
final class MorningBriefCommand extends Command
{
    protected $signature = 'group:morning-brief {--date= : Brief date, YYYY-MM-DD (default today, business time)}';

    protected $description = 'Send the morning brief: yesterday per site, once per date';

    public function handle(CommandBus $bus, MorningBrief $brief, ExceptionRules $rules): int
    {
        $date = $this->option('date');
        $date = is_string($date) && $date !== '' ? $date : CarbonImmutable::now($rules->timezone())->toDateString();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $this->error('The date must be YYYY-MM-DD.');

            return self::FAILURE;
        }
        $id = MorningBrief::idFor($date);
        if (Brief::query()->whereKey($id)->exists()) {
            $this->info("Brief for {$date} already sent.");

            return self::SUCCESS;
        }
        $built = $brief->build($date);
        try {
            $result = $bus->dispatch(new SendBrief(CommandContext::system()->withEventId($id), [
                'brief_id' => $id, 'day' => $date, 'channel' => 'whatsapp', 'recipient_ids' => [], 'lines' => $built['lines'],
            ]));
        } catch (Rejection $r) {
            $this->error($r->getMessage());

            return self::FAILURE;
        }
        $this->info($result->events === [] ? "Brief for {$date} already sent." : "Brief for {$date} sent.");

        return self::SUCCESS;
    }
}
