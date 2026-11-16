<?php

declare(strict_types=1);

namespace Tanaad\Costing\Support;

use Carbon\CarbonImmutable;
use Tanaad\Config\Support\Payload;

/** A costing period: a calendar month (YYYY-MM) or an explicit from/to range of at most 366 days. */
final readonly class Period
{
    public const int MAX_DAYS = 366;

    public function __construct(public string $label, public string $from, public string $to) {}

    /**
     * Reads `month` or `from`+`to`; errors go into the Payload (caller calls check()).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function read(Payload $p, array $raw): ?self
    {
        $month = $raw['month'] ?? null;
        if ($month !== null) {
            if (! is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
                $p->error('month', 'must be a month (YYYY-MM)');

                return null;
            }

            return self::month($month);
        }
        $from = $p->date('from');
        $to = $p->date('to');
        if ($from === '' || $to === '') {
            return null;
        }
        if ($from > $to) {
            $p->error('to', 'must not be before from');

            return null;
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            $p->error('to', 'a period is at most 366 days');

            return null;
        }

        return new self("{$from}..{$to}", $from, $to);
    }

    public static function month(string $month): self
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC') ?: throw new \InvalidArgumentException('Bad month');

        return new self($month, $start->toDateString(), $start->endOfMonth()->toDateString());
    }

    public static function today(): string
    {
        return CarbonImmutable::now()->setTimezone(CostBook::timezone())->toDateString();
    }
}
