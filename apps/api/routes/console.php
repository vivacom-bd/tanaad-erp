<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Archive casuals absent longer than their site's archive_after_absent_days (P1-03).
Schedule::command('people:archive-absent')->dailyAt('02:00')->timezone((string) config('tanaad.timezone'));

// contract_expiring exceptions at each site's contract_warning_days (P1-07).
Schedule::command('people:contract-expiry')->dailyAt('06:00')->timezone((string) config('tanaad.timezone'));

// Morning brief: yesterday per site, once per date, as group.brief_sent (P4-04).
Schedule::command('group:morning-brief')->dailyAt('06:30')->timezone((string) config('tanaad.timezone'));

// Nightly integrity check of the event ledger; non-zero exit and a critical log line on failure (P4-07).
Schedule::command('ledger:verify')->dailyAt('03:30')->timezone((string) config('tanaad.timezone'))->onOneServer();

// Freeze last month's P&L and unit costs per site from cash-closed days (P4-03).
Schedule::command('costing:close-period')->monthlyOn(1, '03:00')->timezone((string) config('tanaad.timezone'))->onOneServer();
