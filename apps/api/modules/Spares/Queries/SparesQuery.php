<?php

declare(strict_types=1);

namespace Tanaad\Spares\Queries;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Money\Rounding;

/** Read shapes (prototype camelCase): the parts store and its issue log. */
final class SparesQuery
{
    public const int ISSUE_LIMIT = 500;

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array{parts: list<array<string, mixed>>, issues: list<array<string, mixed>>} */
    public function store(string $siteId): array
    {
        $parts = $this->db->table('parts')->where('site_id', $siteId)->whereNull('deleted_at')->orderBy('code')->get();
        $issues = $this->db->table('part_issues')->where('site_id', $siteId)->whereNull('deleted_at')
            ->orderByDesc('at')->orderByDesc('seq')->limit(self::ISSUE_LIMIT)->get();

        return [
            'parts' => array_values($parts->map(fn (\stdClass $r): array => self::part($r))->all()),
            'issues' => array_values($issues->map(fn (\stdClass $r): array => self::issue($r))->all()),
        ];
    }

    /** @return array<string, mixed> */
    public static function part(\stdClass $r): array
    {
        $kinds = json_decode((string) ($r->machine_kinds ?? '[]'), true);

        return [
            'id' => (string) $r->id, 'siteId' => (string) $r->site_id, 'code' => (string) $r->code, 'name' => (string) $r->name,
            'unit' => (string) $r->unit, 'minLevel' => (float) $r->min_level, 'unitCost' => (float) $r->unit_cost,
            'onHand' => (float) $r->on_hand, 'machineKinds' => is_array($kinds) ? array_values($kinds) : [], 'active' => (bool) $r->active,
            'belowMinimum' => (float) $r->on_hand < (float) $r->min_level,
        ];
    }

    /** @return array<string, mixed> */
    public static function issue(\stdClass $r): array
    {
        return [
            'id' => (string) $r->id, 'siteId' => (string) $r->site_id, 'partId' => (string) $r->part_id, 'machineId' => (string) $r->machine_id,
            'mechanicId' => $r->mechanic_id, 'breakdownId' => $r->breakdown_id, 'qty' => (float) $r->qty, 'unitCost' => (float) $r->unit_cost,
            'cost' => Rounding::money((string) $r->cost), 'reason' => $r->reason, 'issuedBy' => $r->issued_by,
            'at' => (new \DateTimeImmutable((string) $r->at, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
