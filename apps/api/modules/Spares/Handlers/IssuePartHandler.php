<?php

declare(strict_types=1);

namespace Tanaad\Spares\Handlers;

use Brick\Math\BigDecimal;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;
use Tanaad\Spares\Commands\IssuePart;
use Tanaad\Spares\Support\Spares;

/**
 * Parts out of the store to a machine. The issuing actor (storekeeper or supervisor) is the
 * authenticated session; it is the "PIN" of the prototype. The part row is read WITH a lock, so two
 * concurrent issues cannot both take the last unit; more than is on hand is 409. The cost is
 * qty × the part's current average cost, both carried in the event.
 */
final class IssuePartHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof IssuePart) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $partId = $p->uuid('part_id');
        $issueId = $p->optUuid('issue_id') ?? (string) Str::uuid7();
        $machineId = $p->uuid('machine_id');
        $mechanicId = $p->optUuid('mechanic_id');
        $breakdownId = $p->optUuid('breakdown_id');
        $reason = $p->optStr('reason', 255);
        $qty = Spares::qty($p, 'qty', true);
        $p->check();

        $part = Spares::lockedPart($this->db, $siteId, $partId);
        $machine = $this->db->table('machines')->where(['id' => $machineId, 'site_id' => $siteId])->whereNull('deleted_at')->first();
        if ($machine === null) {
            throw Rejection::invalidPayload(['machine_id' => 'no such machine at this site']);
        }
        if ($mechanicId !== null) {
            $person = $this->db->table('people')->where('id', $mechanicId)->whereNull('deleted_at')->first(['site_id', 'shared', 'shared_sites']);
            $sites = $person === null ? [] : json_decode((string) ($person->shared_sites ?? '[]'), true);
            if ($person === null || ($person->site_id !== $siteId && ! (bool) $person->shared && ! in_array($siteId, is_array($sites) ? $sites : [], true))) {
                throw Rejection::invalidPayload(['mechanic_id' => 'no such person for this site']);
            }
        }
        if ($breakdownId !== null) {
            $b = $this->db->table('breakdowns')->where(['id' => $breakdownId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
            if ($b === null) {
                throw Rejection::invalidPayload(['breakdown_id' => 'no such breakdown at this site']);
            }
            if ($b->machine_id !== $machineId) {
                throw Rejection::invalidPayload(['breakdown_id' => 'belongs to another machine']);
            }
            if ($b->resolved_at !== null) {
                throw Rejection::stateConflict('That breakdown is already resolved.');
            }
        }
        if ($this->db->table('part_issues')->where('id', $issueId)->exists()) {
            throw Rejection::duplicateFact("Issue {$issueId} is already recorded.");
        }
        $onHand = BigDecimal::of((string) $part->on_hand);
        $qtyD = BigDecimal::of(Spares::dec($qty));
        if ($qtyD->isGreaterThan($onHand)) {
            throw Rejection::stateConflict("Only {$onHand->toFloat()} {$part->unit} of {$part->name} on hand.");
        }
        $unitCost = BigDecimal::of((string) $part->unit_cost);
        $after = $onHand->minus($qtyD);

        return [Event::from($ctx, 'spares.part_issued', [
            'issue_id' => $issueId, 'part_id' => $partId, 'part_code' => (string) $part->code, 'part_name' => (string) $part->name,
            'unit' => (string) $part->unit, 'machine_id' => $machineId, 'machine_code' => (string) $machine->code,
            'mechanic_id' => $mechanicId, 'breakdown_id' => $breakdownId, 'reason' => $reason, 'qty' => $qty,
            'unit_cost' => $unitCost->toFloat(), 'cost' => Rounding::money($qtyD->multipliedBy($unitCost)),
            'on_hand_after' => $after->toFloat(), 'min_level' => (float) $part->min_level,
        ])];
    }
}
