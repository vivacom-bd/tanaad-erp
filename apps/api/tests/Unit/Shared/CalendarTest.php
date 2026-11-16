<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tanaad\Shared\Calendar\Calendar;

$cal = fn () => Calendar::forSite('mats', [
    ['kind' => 'day', 'starts' => '07:00', 'ends' => '19:00'],
    ['kind' => 'night', 'starts' => '19:00', 'ends' => '07:00'],
]);

it('puts a daytime event on its own day', function () use ($cal): void {
    $at = CarbonImmutable::parse('2026-11-09 13:20', 'Africa/Kampala');
    expect($cal()->businessDay($at))->toBe('2026-11-09')
        ->and($cal()->shiftKindAt($at))->toBe('day')
        ->and($cal()->shiftIdAt($at))->toBe('sh_mats_2026-11-09_day');
});

it('puts the tail of the night shift on the day it started', function () use ($cal): void {
    $at = CarbonImmutable::parse('2026-11-10 03:00', 'Africa/Kampala');
    expect($cal()->businessDay($at))->toBe('2026-11-09')
        ->and($cal()->shiftIdAt($at))->toBe('sh_mats_2026-11-09_night');
});

it('starts the night shift at 19:00 on the same day', function () use ($cal): void {
    expect($cal()->shiftIdAt(CarbonImmutable::parse('2026-11-09 19:00', 'Africa/Kampala')))->toBe('sh_mats_2026-11-09_night')
        ->and($cal()->shiftIdAt(CarbonImmutable::parse('2026-11-09 18:59', 'Africa/Kampala')))->toBe('sh_mats_2026-11-09_day');
});

it('works from UTC timestamps', function () use ($cal): void {
    // 00:30 UTC is 03:30 in Kampala, still the previous business day.
    expect($cal()->businessDay(CarbonImmutable::parse('2026-11-10 00:30:00Z')))->toBe('2026-11-09');
});

it('returns no shift when the table has a gap', function (): void {
    $cal = Calendar::forSite('x', [['kind' => 'day', 'starts' => '08:00', 'ends' => '17:00']]);
    expect($cal->shiftKindAt(CarbonImmutable::parse('2026-11-09 20:00', 'Africa/Kampala')))->toBeNull()
        ->and($cal->businessDay(CarbonImmutable::parse('2026-11-09 20:00', 'Africa/Kampala')))->toBe('2026-11-09');
});
