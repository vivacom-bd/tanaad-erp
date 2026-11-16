<?php

declare(strict_types=1);

namespace Tanaad\Spares\Handlers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Spares\Commands\SavePart;
use Tanaad\Spares\Support\Spares;

/**
 * The parts catalogue is master data: code, name, unit, minimum level, unit cost and the machine
 * kinds a part fits are all user-managed. Stock is not set here; it only moves by receipts and issues.
 */
final class SavePartHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof SavePart) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $partId = $p->optUuid('part_id') ?? (string) Str::uuid7();
        $payload = [
            'part_id' => $partId,
            'code' => $p->str('code', 32),
            'name' => $p->str('name', 255),
            'unit' => $p->str('unit', 32),
            'min_level' => Spares::qty($p, 'min_level', false),
            'unit_cost' => $p->num('unit_cost'),
            'machine_kinds' => $this->kinds($p, $command->payload['machine_kinds'] ?? []),
            'active' => $p->bool('active', true),
        ];
        if ($payload['unit_cost'] > Spares::MAX_COST) {
            $p->error('unit_cost', 'must be at most '.Spares::MAX_COST);
        }
        $p->check();

        // Serialises two first saves of one code at the site.
        $this->db->table('sites')->where('id', $siteId)->lockForUpdate()->value('id');
        $existing = $this->db->table('parts')->where('id', $partId)->lockForUpdate()->first();
        if ($existing !== null && ($existing->site_id !== $siteId || $existing->deleted_at !== null)) {
            throw Rejection::notFound('Part', $partId);
        }
        $clash = $this->db->table('parts')->where(['site_id' => $siteId, 'code' => $payload['code']])->whereNull('deleted_at')->where('id', '!=', $partId)->exists();
        if ($clash) {
            throw Rejection::duplicateFact("Part code {$payload['code']} is already used.");
        }
        // The stock at save time rides along so a lowered minimum can settle a low_stock exception on replay.
        $payload['on_hand'] = $existing === null ? 0.0 : (float) $existing->on_hand;

        return [Event::from($ctx, 'spares.part_saved', $payload)];
    }

    /**
     * @return list<string>
     */
    private function kinds(Payload $p, mixed $raw): array
    {
        if (! is_array($raw) || count($raw) > 50) {
            $p->error('machine_kinds', 'must be a list of at most 50 machine kinds');

            return [];
        }
        $out = [];
        foreach ($raw as $kind) {
            if (! is_string($kind) || trim($kind) === '' || mb_strlen($kind) > 32) {
                $p->error('machine_kinds', 'each kind must be a short text');

                return [];
            }
            $out[trim($kind)] = true;
        }

        return array_map('strval', array_keys($out));
    }
}
