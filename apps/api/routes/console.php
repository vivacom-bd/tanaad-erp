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
