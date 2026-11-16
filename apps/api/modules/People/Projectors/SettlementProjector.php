<?php

declare(strict_types=1);

namespace Tanaad\People\Projectors;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * The only writer of settlement_runs, settlement_lines, payouts and advances. Lines come
 * whole from the run event; nothing is recomputed here.
 */
final class SettlementProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['people.settlement_run_created', 'people.settlement_approved', 'people.settlement_cancelled', 'people.payout_recorded', 'people.advance_given'];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        $touch = ['seq' => $event->seq, 'updated_at' => $event->receivedAt];
        $new = ['site_id' => $event->siteId(), 'deleted_at' => null, 'created_at' => $event->receivedAt, ...$touch];

        match ($event->type()) {
            'people.settlement_run_created' => $this->run($event, $p, $new, $touch),
            'people.settlement_approved' => $this->db->table('settlement_runs')->where('id', $p['settlement_run_id'])
                ->update([
                    'status' => $this->hasPayable((string) $p['settlement_run_id']) ? 'approved' : 'paid',
                    'approved_by' => $event->event->actorId, 'approved_at' => $event->event->occurredAt, ...$touch,
                ]),
            'people.settlement_cancelled' => $this->cancel($p, $touch),
            'people.payout_recorded' => $this->payout($event, $p, $new, $touch),
            'people.advance_given' => $this->db->table('advances')->insert([
                'id' => $p['advance_id'], 'person_id' => $p['person_id'], 'amount' => $p['amount'], 'deducted' => '0.00',
                'balance' => $p['amount'], 'reason' => $p['reason'], 'given_on' => $p['given_on'], 'given_by' => $event->event->actorId, ...$new,
            ]),
            default => null,
        };
    }

    public function reset(): void
    {
        foreach (['advances', 'payouts', 'settlement_lines', 'settlement_runs'] as $table) {
            $this->db->table($table)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $touch
     */
    private function run(StoredEvent $event, array $p, array $new, array $touch): void
    {
        /** @var array<string, mixed> $t */
        $t = $p['totals'];
        /** @var list<array<string, mixed>> $lines */
        $lines = $p['lines'];
        $this->db->table('settlement_runs')->insert([
            'id' => $p['settlement_run_id'], 'kind' => $p['kind'], 'period_from' => $p['period_from'], 'period_to' => $p['period_to'],
            'currency' => $p['currency'], 'status' => 'created', 'standard_day_hours' => $p['standard_day_hours'],
            'month_working_days' => $p['month_working_days'], 'line_count' => count($lines), 'total_units' => $t['units'],
            'total_gross' => $t['gross'], 'total_overtime' => $t['overtime'], 'total_bonus' => $t['bonus'],
            'total_advances' => $t['advances_deducted'], 'total_net' => $t['net'], 'created_by' => $event->event->actorId,
            'created_event_id' => $event->id(), ...$new,
        ]);
        foreach ($lines as $l) {
            /** @var list<array{advance_id: string, amount: string}> $deductions */
            $deductions = $l['deductions'];
            $this->db->table('settlement_lines')->insert([
                'id' => $l['line_id'], 'settlement_run_id' => $p['settlement_run_id'], 'person_id' => $l['person_id'],
                'engagement_id' => $l['engagement_id'], 'period_from' => $l['period_from'], 'period_to' => $l['period_to'],
                'rate_basis' => $l['rate_basis'], 'rate' => $l['rate'], 'units' => $l['units'], 'overtime_hours' => $l['overtime_hours'],
                'gross' => $l['gross'], 'overtime' => $l['overtime'], 'bonus' => $l['bonus'], 'advances_deducted' => $l['advances_deducted'],
                'net' => $l['net'], 'deductions' => json_encode($deductions, JSON_THROW_ON_ERROR), 'status' => 'unpaid', 'payout_id' => null, ...$new,
            ]);
            foreach ($deductions as $d) {
                $row = $this->db->table('advances')->where('id', $d['advance_id'])->first(['amount', 'deducted']);
                if ($row === null) {
                    continue;
                }
                $deducted = BigDecimal::of((string) $row->deducted)->plus($d['amount'])->toScale(2);
                $this->db->table('advances')->where('id', $d['advance_id'])->update([
                    'deducted' => (string) $deducted, 'balance' => (string) BigDecimal::of((string) $row->amount)->minus($deducted)->toScale(2), ...$touch,
                ]);
            }
        }
    }

    /**
     * Advances are deducted when the run is created (see run()), so cancelling gives each
     * deduction back to its advance. The lines stay as history, marked cancelled, and stop
     * blocking a fresh run over the same period.
     *
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $touch
     */
    private function cancel(array $p, array $touch): void
    {
        $lines = $this->db->table('settlement_lines')->where('settlement_run_id', $p['settlement_run_id'])->get(['deductions']);
        foreach ($lines as $line) {
            /** @var list<array{advance_id: string, amount: string}> $deductions */
            $deductions = json_decode((string) $line->deductions, true, 512, JSON_THROW_ON_ERROR);
            foreach ($deductions as $d) {
                $row = $this->db->table('advances')->where('id', $d['advance_id'])->first(['amount', 'deducted']);
                if ($row === null) {
                    continue;
                }
                $deducted = BigDecimal::of((string) $row->deducted)->minus($d['amount'])->toScale(2);
                $this->db->table('advances')->where('id', $d['advance_id'])->update([
                    'deducted' => (string) $deducted, 'balance' => (string) BigDecimal::of((string) $row->amount)->minus($deducted)->toScale(2), ...$touch,
                ]);
            }
        }
        $this->db->table('settlement_lines')->where('settlement_run_id', $p['settlement_run_id'])->update(['status' => 'cancelled', ...$touch]);
        $this->db->table('settlement_runs')->where('id', $p['settlement_run_id'])->update(['status' => 'cancelled', ...$touch]);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $touch
     */
    private function payout(StoredEvent $event, array $p, array $new, array $touch): void
    {
        $line = $this->db->table('settlement_lines')
            ->where(['settlement_run_id' => $p['settlement_run_id'], 'person_id' => $p['person_id']])->first(['id', 'status']);
        if ($line === null || $line->status === 'cancelled') {
            return;
        }
        $this->db->table('payouts')->insert([
            'id' => $p['payout_id'], 'settlement_run_id' => $p['settlement_run_id'], 'settlement_line_id' => $line->id,
            'person_id' => $p['person_id'], 'amount' => $p['amount'], 'method' => $p['method'], 'reference' => $p['reference'] ?? null,
            'paid_at' => \Carbon\CarbonImmutable::parse((string) $p['paid_at'])->utc(), 'recorded_by' => $event->event->actorId, ...$new,
        ]);
        $this->db->table('settlement_lines')->where('id', $line->id)->update(['status' => 'paid', 'payout_id' => $p['payout_id'], ...$touch]);
        if (! $this->hasPayable((string) $p['settlement_run_id'])) {
            // Never revive a cancelled run (the handler rejects that; this guards replay of old ledgers).
            $this->db->table('settlement_runs')->where('id', $p['settlement_run_id'])->where('status', '!=', 'cancelled')
                ->update(['status' => 'paid', ...$touch]);
        }
    }

    /** An unpaid line with net 0 (advances took the whole pay) needs no payout, so it counts as settled. */
    private function hasPayable(string $runId): bool
    {
        return $this->db->table('settlement_lines')->where(['settlement_run_id' => $runId, 'status' => 'unpaid'])->where('net', '>', 0)->exists();
    }
}
