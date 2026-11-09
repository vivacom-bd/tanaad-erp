<?php

declare(strict_types=1);

namespace Tanaad\Shared\Calendar;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Business day and shift from an occurred_at and a site's shift table (ADR-008).
 * A shift that crosses midnight belongs to the calendar day on which it started, so an
 * event at 03:00 during the night shift has the previous day as its business day.
 * Shift ids match the prototype: sh_{site}_{YYYY-MM-DD}_{kind}.
 */
final readonly class Calendar
{
    /** @param list<Shift> $shifts */
    public function __construct(
        private string $siteCode,
        private array $shifts,
        private string $timezone = 'Africa/Kampala',
    ) {}

    /** @param list<array{kind: string, starts: string, ends: string}> $rows */
    public static function forSite(string $siteCode, array $rows, string $timezone = 'Africa/Kampala'): self
    {
        return new self($siteCode, array_map(Shift::fromArray(...), $rows), $timezone);
    }

    public function local(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($this->timezone);
    }

    public function shiftAt(DateTimeInterface $at): ?Shift
    {
        $minute = $this->local($at)->hour * 60 + $this->local($at)->minute;
        foreach ($this->shifts as $shift) {
            if ($shift->crossesMidnight()) {
                if ($minute >= $shift->startMinute() || $minute < $shift->endMinute()) {
                    return $shift;
                }
            } elseif ($minute >= $shift->startMinute() && $minute < $shift->endMinute()) {
                return $shift;
            }
        }

        return null;
    }

    /** YYYY-MM-DD in the business timezone, shifted back a day inside the tail of a night shift. */
    public function businessDay(DateTimeInterface $at): string
    {
        $local = $this->local($at);
        $shift = $this->shiftAt($at);
        $minute = $local->hour * 60 + $local->minute;
        if ($shift !== null && $shift->crossesMidnight() && $minute < $shift->endMinute()) {
            $local = $local->subDay();
        }

        return $local->toDateString();
    }

    public function shiftKindAt(DateTimeInterface $at): ?string
    {
        return $this->shiftAt($at)?->kind;
    }

    public function shiftId(string $day, string $kind): string
    {
        return "sh_{$this->siteCode}_{$day}_{$kind}";
    }

    public function shiftIdAt(DateTimeInterface $at): ?string
    {
        $kind = $this->shiftKindAt($at);

        return $kind === null ? null : $this->shiftId($this->businessDay($at), $kind);
    }
}
