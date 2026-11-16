<?php

declare(strict_types=1);

namespace Tanaad\Documents\Builders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Tanaad\Documents\Support\Company;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Rejection;

/** A payslip for one settlement line. Group roles, the site's manager, and the worker themself (user.person_id). */
final class PayslipBuilder implements DocumentBuilder
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Access $access, private readonly Company $company) {}

    public function available(): bool
    {
        return Schema::hasTable('settlement_lines') && Schema::hasTable('settlement_runs');
    }

    public function build(User $user, string $id): array
    {
        $line = $this->db->table('settlement_lines')->where('id', $id)->whereNull('deleted_at')->first();
        if ($line === null) {
            throw Rejection::notFound('Settlement line', $id);
        }
        $own = $user->person_id !== null && $user->person_id === $line->person_id;
        if (! $own && ! $this->access->canAct($user, $line->site_id, [Role::SiteManager->value])) {
            throw Rejection::forbidden('You may not print this payslip.');
        }
        $run = $this->db->table('settlement_runs')->where('id', $line->settlement_run_id)->first();
        $person = $this->db->table('people')->where('id', $line->person_id)->first(['number', 'full_name']);
        $payout = $line->payout_id === null ? null : $this->db->table('payouts')->where('id', $line->payout_id)->first(['amount', 'method', 'reference', 'paid_at']);

        return [
            ...$this->company->forSite($line->site_id),
            'line_id' => $line->id,
            'run_id' => $line->settlement_run_id,
            'run_status' => $run->status ?? null,
            'currency' => $run->currency ?? null,
            'worker' => ['person_id' => $line->person_id, 'number' => $person->number ?? null, 'name' => $person->full_name ?? null],
            'period' => ['from' => $line->period_from, 'to' => $line->period_to],
            'rate_basis' => $line->rate_basis,
            'rate' => $line->rate,
            'units' => $line->units,
            'overtime_hours' => $line->overtime_hours,
            'deductions' => json_decode((string) $line->deductions, true, 512, JSON_THROW_ON_ERROR),
            'totals' => ['gross' => $line->gross, 'overtime' => $line->overtime, 'bonus' => $line->bonus, 'advances_deducted' => $line->advances_deducted, 'net' => $line->net],
            'status' => $line->status,
            'payout' => $payout === null ? null : ['amount' => $payout->amount, 'method' => $payout->method, 'reference' => $payout->reference, 'paid_at' => $payout->paid_at],
        ];
    }
}
