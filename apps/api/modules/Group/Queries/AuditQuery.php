<?php

declare(strict_types=1);

namespace Tanaad\Group\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Support\AuditText;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Shared\Ledger\Rejection;

/** A readable, paginated feed over the append-only events table. Never exposes raw payloads. */
final class AuditQuery
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    /** Deep offsets are refused: narrow with site/type/from/to instead. */
    public const int MAX_PAGE = 1000;

    public function __construct(private readonly ConnectionInterface $db, private readonly ExceptionRules $rules) {}

    /**
     * @param  list<string>|null  $readable  site ids the caller may read, null = all (and group-wide events)
     * @param  array{site?: mixed, type?: mixed, from?: mixed, to?: mixed, page?: mixed, per_page?: mixed}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function page(?array $readable, array $filters): array
    {
        $errors = [];
        $site = $this->str($filters['site'] ?? null);
        $type = $this->str($filters['type'] ?? null);
        if ($type !== null && preg_match('/^[a-z_]{1,60}(\.[a-z_]{0,60})?$/', $type) !== 1) {
            $errors['type'] = 'must be an event type such as cash.day_closed, or a family such as cash';
        }
        [$from, $fromErr] = $this->day($filters['from'] ?? null, false);
        [$to, $toErr] = $this->day($filters['to'] ?? null, true);
        $errors += array_filter(['from' => $fromErr, 'to' => $toErr]);
        $page = $this->int($filters['page'] ?? null, 1, 1, self::MAX_PAGE);
        $perPage = $this->int($filters['per_page'] ?? null, self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE);
        if ($page === null) {
            $errors['page'] = 'must be between 1 and '.self::MAX_PAGE;
        }
        if ($perPage === null) {
            $errors['per_page'] = 'must be between 1 and '.self::MAX_PER_PAGE;
        }
        $siteId = null;
        if ($site !== null) {
            $siteId = $this->db->table('sites')->where('code', $site)->orWhere('id', $site)->value('id');
            if ($siteId === null) {
                $errors['site'] = 'unknown site';
            } elseif ($readable !== null && ! in_array((string) $siteId, $readable, true)) {
                throw Rejection::forbidden('You may only read the audit feed of your own site.');
            }
        }
        if ($errors !== [] || $page === null || $perPage === null) {
            throw Rejection::invalidPayload($errors);
        }

        $q = $this->db->table('events');
        if ($siteId !== null) {
            $q->where('site_id', (string) $siteId);
        } elseif ($readable !== null) {
            $q->whereIn('site_id', $readable);
        }
        if ($type !== null) {
            str_contains($type, '.') && ! str_ends_with($type, '.') ? $q->where('type', $type) : $q->where('type', 'like', rtrim($type, '.').'.%');
        }
        if ($from !== null) {
            $q->where('occurred_at', '>=', $from);
        }
        if ($to !== null) {
            $q->where('occurred_at', '<', $to);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('seq')->forPage($page, $perPage)
            ->get(['seq', 'id', 'site_id', 'type', 'actor_id', 'occurred_at', 'payload', 'corrects_event_id', 'reason']);

        $actors = $this->db->table('users')->whereIn('id', $rows->pluck('actor_id')->filter()->unique()->all())->pluck('name', 'id');
        $codes = $this->db->table('sites')->pluck('code', 'id');
        $data = [];
        foreach ($rows as $r) {
            $payload = json_decode((string) $r->payload, true);
            $facts = AuditText::facts((string) $r->type, is_array($payload) ? $payload : []);
            $reason = $r->reason ?? (is_array($payload) && is_string($payload['reason'] ?? null) ? mb_substr($payload['reason'], 0, 500) : null);
            $data[] = [
                'id' => (string) $r->id, 'seq' => (int) $r->seq, 'type' => (string) $r->type,
                'at' => CarbonImmutable::parse((string) $r->occurred_at, 'UTC')->toIso8601ZuluString('microsecond'),
                'siteId' => $r->site_id, 'siteCode' => $r->site_id === null ? null : ($codes[$r->site_id] ?? null),
                'actorId' => $r->actor_id, 'actorName' => $r->actor_id === null ? 'System' : ($actors[$r->actor_id] ?? null),
                'summary' => AuditText::summary((string) $r->type, $facts),
                'reason' => $reason, 'flag' => AuditText::flag((string) $r->type, $facts, $r->corrects_event_id !== null),
                'correctsEventId' => $r->corrects_event_id, 'facts' => (object) $facts,
            ];
        }

        return ['data' => $data, 'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => max(1, (int) ceil($total / $perPage))]];
    }

    private function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function int(mixed $v, int $default, int $min, int $max): ?int
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (! is_numeric($v) || (string) (int) $v !== (string) $v || (int) $v < $min || (int) $v > $max) {
            return null;
        }

        return (int) $v;
    }

    /**
     * A business day as a UTC instant: the start of the day, or (end=true) the start of the next.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function day(mixed $v, bool $end): array
    {
        if ($v === null || $v === '') {
            return [null, null];
        }
        if (! is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [null, 'must be a date, YYYY-MM-DD'];
        }
        $t = CarbonImmutable::parse($v, $this->rules->timezone())->startOfDay();

        return [($end ? $t->addDay() : $t)->utc()->format('Y-m-d H:i:s.u'), null];
    }
}
