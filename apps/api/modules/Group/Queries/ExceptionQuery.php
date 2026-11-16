<?php

declare(strict_types=1);

namespace Tanaad\Group\Queries;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Models\BusinessException;

/** The Approvals list, in the prototype's BusinessException shape. Open and held first, newest first. */
final class ExceptionQuery
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  list<string>|null  $siteIds  null = every site
     * @return list<array<string, mixed>>
     */
    public function list(?array $siteIds, ?string $kind, ?string $status): array
    {
        $codes = $this->db->table('sites')->pluck('code', 'id')->all();

        return array_values(BusinessException::query()
            ->when($siteIds !== null, fn ($q) => $q->whereIn('site_id', $siteIds ?? []))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw("case when status in ('open', 'held') then 0 else 1 end")
            ->orderByDesc('raised_at')->orderByDesc('seq')
            ->limit(500)->get()
            ->map(fn (BusinessException $e): array => self::shape($e, $codes[$e->site_id] ?? null))->all());
    }

    /** Resolve a site code or id to an id. */
    public function siteId(string $codeOrId): ?string
    {
        $id = $this->db->table('sites')->where('code', $codeOrId)->orWhere('id', $codeOrId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /** @return array<string, mixed> */
    public static function shape(BusinessException $e, mixed $siteCode = null): array
    {
        return [
            'id' => $e->id, 'siteId' => $e->site_id, 'siteCode' => is_string($siteCode) ? $siteCode : null, 'kind' => $e->kind,
            'severity' => $e->severity, 'title' => $e->title, 'detail' => $e->detail, 'subjectRef' => $e->subject_ref,
            'facts' => (object) ($e->facts ?? []), 'status' => $e->status, 'ownerRole' => $e->owner_role,
            'raisedBy' => $e->raised_by, 'raisedAt' => $e->raised_at->toIso8601ZuluString('microsecond'),
            'decision' => $e->decision, 'decidedBy' => $e->decided_by, 'decidedAt' => $e->decided_at?->toIso8601ZuluString('microsecond'),
            'decisionReason' => $e->decision_note,
        ];
    }
}
