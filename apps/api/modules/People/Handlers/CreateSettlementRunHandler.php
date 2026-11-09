<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Tanaad\Config\Models\Site;
use Tanaad\Config\Models\SiteSetting;
use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\CreateSettlementRun;
use Tanaad\People\Models\Advance;
use Tanaad\People\Models\Attendance;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Models\Person;
use Tanaad\People\Models\SettlementLine;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/**
 * Computes the whole run here and puts every line in the event, so replay never
 * recomputes from attendance or advances that may have changed since.
 *
 * Eligibility (engagements at the command's site):
 * - weekly_casual: casual engagements on the weekly cycle, active; units within from..to.
 * - monthly: engagements on the monthly cycle, active; units within from..to.
 * - end_of_term: engagements on the end_of_term cycle, active or ended, whose end_date
 *   is on or before `to`; units over the engagement's own term (start_date..end_date).
 * Each line's window is also clipped to the engagement's start and end dates.
 *
 * Units: present = 1, half = 0.5 per attendance row at this site; overtime hours are the
 * sum of the rows' overtime_hours. Month basis pays units / month_working_days of the rate.
 * Overtime per hour is the day rate (month rate / month_working_days) / standard_day_hours,
 * both from the site settings. Bonuses come from the payload. Each person's outstanding
 * advances given on or before `to` are deducted oldest first, but never more than the
 * line's gross + overtime + bonus: net never goes below 0 and the rest stays on the advance.
 * The site row is locked first, so concurrent runs at a site serialise and see each
 * other's deductions and settled periods.
 * One line per person per run: if a person has two eligible engagements, the one that
 * ends first is settled and the other waits for the next run.
 */
final class CreateSettlementRunHandler implements Handler
{
    public const array KINDS = ['weekly_casual', 'end_of_term', 'monthly'];

    public function handle(Command $command): array
    {
        if (! $command instanceof CreateSettlementRun) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $runId = $p->optUuid('settlement_run_id') ?? (string) Str::uuid7();
        $kind = $p->oneOf('kind', self::KINDS);
        $from = $p->date('from');
        $to = $p->date('to');
        if ($from !== '' && $to !== '' && $to < $from) {
            $p->error('to', 'must not be before from');
        }
        $bonuses = [];
        foreach ($p->has('bonuses') ? $p->children('bonuses') : [] as $b) {
            $bonuses[$b->uuid('person_id')] = $b->num('amount');
        }
        $p->check();

        $settings = SiteSetting::query()->where('site_id', $site->id)->first();
        $dayHours = (float) ($settings->standard_day_hours ?? 0);
        $monthDays = (float) ($settings->month_working_days ?? 0);
        if ($dayHours <= 0 || $monthDays <= 0) {
            throw Rejection::staleConfig('Set standard day hours and month working days for this site before settling.');
        }

        // Serialise run creation per site (CommandBus wraps this in a transaction).
        Site::query()->whereKey($site->id)->lockForUpdate()->first();

        $engagements = $this->eligible($site->id, $kind, $to);
        $lines = [];
        $totals = array_fill_keys(['units', 'gross', 'overtime', 'bonus', 'advances_deducted', 'net'], BigDecimal::zero());
        $currency = null;
        foreach ($engagements as $e) {
            if (isset($lines[$e->person_id])) {
                continue;
            }
            [$lineFrom, $lineTo] = $kind === 'end_of_term'
                ? [$e->start_date, (string) $e->end_date]
                : [max($from, $e->start_date), $e->end_date === null ? $to : min($to, $e->end_date)];
            if ($lineTo < $lineFrom) {
                continue;
            }
            $rows = Attendance::query()->where(['person_id' => $e->person_id, 'site_id' => $site->id])
                ->whereBetween('day', [$lineFrom, $lineTo])->get(['status', 'overtime_hours']);
            $units = BigDecimal::zero();
            $otHours = BigDecimal::zero();
            foreach ($rows as $row) {
                $units = $units->plus(match ($row->status) {
                    'present' => '1', 'half' => '0.5', default => '0'
                });
                $otHours = $otHours->plus(Rounding::to($row->overtime_hours, 2));
            }
            $bonus = $bonuses[$e->person_id] ?? 0.0;
            if ($units->isZero() && $otHours->isZero() && $bonus == 0.0) {
                continue;
            }
            if (SettlementLine::query()->where('engagement_id', $e->id)->where('status', '!=', 'cancelled')
                ->where('period_from', '<=', $lineTo)->where('period_to', '>=', $lineFrom)->exists()) {
                throw Rejection::duplicateFact("Engagement {$e->id} is already settled for part of {$lineFrom} to {$lineTo}.");
            }
            if ($currency !== null && $currency !== $e->currency) {
                throw Rejection::stateConflict('A run settles one currency; these engagements mix currencies.');
            }
            $currency = $e->currency;

            $isMonth = $e->rate_basis === 'month';
            $dayRate = $isMonth ? $e->rate / $monthDays : $e->rate;
            $in = [
                'rateBasis' => $e->rate_basis, 'rate' => $e->rate,
                'unitsWorked' => $isMonth ? $units->toFloat() / $monthDays : $units->toFloat(),
                'overtimeHours' => $otHours->toFloat(), 'overtimeRatePerHour' => $dayRate / $dayHours, 'bonus' => $bonus,
            ];
            // UGX has no minor unit: each component is rounded to whole shillings (half-up, from the
            // 2-place figure, exactly as the prototype does), and net is their exact difference.
            $r = Arithmetic::settlementNet([...$in, 'advances' => 0.0]);
            // Other currencies keep 2 decimal places.
            $round = fn (float|int|string $n): BigDecimal => self::inCurrency($n, $e->currency);
            $c = ['gross' => $round($r['gross']), 'overtime' => $round($r['overtime']), 'bonus' => $round($r['bonus'])];
            $cap = $c['gross']->plus($c['overtime'])->plus($c['bonus']);
            // Advances carry no currency: they are paid from the cash book, which is UGX, so they
            // are deducted only from UGX lines. A non-UGX line leaves them owed for a UGX run.
            [$advances, $deductions] = $e->currency === 'UGX'
                ? $this->advances($site->id, $e->person_id, $lineTo, $cap)
                : ['0.00', []];
            $c['advances_deducted'] = BigDecimal::of($advances);
            $c['net'] = $cap->minus($c['advances_deducted']);
            $lines[$e->person_id] = [
                'line_id' => (string) Str::uuid7(), 'person_id' => $e->person_id, 'engagement_id' => $e->id,
                'period_from' => $lineFrom, 'period_to' => $lineTo, 'rate_basis' => $e->rate_basis, 'rate' => self::money($e->rate),
                'units' => (string) $units->toScale(2), 'overtime_hours' => (string) $otHours->toScale(2),
                'gross' => (string) $c['gross'], 'overtime' => (string) $c['overtime'], 'bonus' => (string) $c['bonus'],
                'advances_deducted' => (string) $c['advances_deducted'], 'net' => (string) $c['net'], 'deductions' => $deductions,
            ];
            foreach (['gross', 'overtime', 'bonus', 'advances_deducted', 'net'] as $k) {
                $totals[$k] = $totals[$k]->plus($c[$k]);
            }
            $totals['units'] = $totals['units']->plus($units);
        }
        if ($lines === []) {
            throw Rejection::stateConflict('Nobody is due for this run: no eligible engagement has worked units in the period.');
        }
        $lines = array_values($lines);
        $sum = fn (string $k): string => (string) $totals[$k]->toScale(2);

        return [Event::from($command->context(), 'people.settlement_run_created', [
            'settlement_run_id' => $runId, 'kind' => $kind, 'period_from' => $from, 'period_to' => $to, 'currency' => $currency,
            'standard_day_hours' => $dayHours, 'month_working_days' => $monthDays, 'lines' => $lines,
            'totals' => [
                'lines' => count($lines), 'units' => $sum('units'), 'gross' => $sum('gross'), 'overtime' => $sum('overtime'),
                'bonus' => $sum('bonus'), 'advances_deducted' => $sum('advances_deducted'), 'net' => $sum('net'),
            ],
        ])];
    }

    /** @return list<Engagement> */
    private function eligible(string $siteId, string $kind, string $to): array
    {
        $q = Engagement::query()->where('site_id', $siteId)
            ->whereIn('person_id', Person::query()->where('status', 'active')->select('id'));
        match ($kind) {
            'weekly_casual' => $q->where(['type' => 'casual', 'settlement_cycle' => 'weekly', 'status' => 'active']),
            'monthly' => $q->where(['settlement_cycle' => 'monthly', 'status' => 'active']),
            default => $q->where('settlement_cycle', 'end_of_term')->whereIn('status', ['active', 'ended'])
                ->whereNotNull('end_date')->where('end_date', '<=', $to),
        };

        /** @var list<Engagement> */
        return $q->orderByRaw('end_date is null')->orderBy('end_date')->orderBy('id')->get()->all();
    }

    /** @return array{0: string, 1: list<array{advance_id: string, amount: string}>} */
    private function advances(string $siteId, string $personId, string $to, BigDecimal $cap): array
    {
        $total = BigDecimal::zero();
        $deductions = [];
        foreach (Advance::query()->where(['site_id' => $siteId, 'person_id' => $personId])->where('balance', '>', 0)
            ->where('given_on', '<=', $to)->orderBy('given_on')->orderBy('id')->lockForUpdate()->get() as $a) {
            $room = $cap->minus($total);
            if (! $room->isPositive()) {
                break;
            }
            $take = BigDecimal::min(self::whole($a->balance), $room)->toScale(2);
            $total = $total->plus($take);
            $deductions[] = ['advance_id' => $a->id, 'amount' => (string) $take];
        }

        return [(string) $total->toScale(2), $deductions];
    }

    public static function money(float|int|string $n): string
    {
        return (string) Rounding::to($n, 2);
    }

    /** Whole shillings for UGX (no minor unit), 2 places for any other currency. */
    public static function inCurrency(float|int|string $n, string $currency): BigDecimal
    {
        return $currency === 'UGX' ? self::whole($n) : Rounding::to($n, 2)->toScale(2);
    }

    /** Whole shillings, half-up from the 2-place figure, kept at scale 2 for the decimal columns ("1235.00"). */
    public static function whole(float|int|string $n): BigDecimal
    {
        return Rounding::to(Rounding::to($n, 2), 0)->toScale(2);
    }
}
