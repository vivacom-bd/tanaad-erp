<?php

declare(strict_types=1);

namespace Tanaad\Group\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\Identity\Role;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Rejection;

/**
 * GET /api/v1/alerts?limit= — the notification bell. Every active (open|held) critical exception in the
 * caller's scope, plus gate holds (gate_variance) of the last 24 hours whatever their status, newest first.
 * Scope: CEO / group admin every site; a site manager the sites they manage; anyone else 403.
 */
final class AlertController extends ApiController
{
    private const int MAX = 100;

    public function __construct(private readonly ConnectionInterface $db, private readonly Access $access) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('Sign in first.');
        }
        $user->loadMissing('siteRoles');
        $siteIds = null;
        if (! $this->access->hasGroupRole($user)) {
            $siteIds = array_values($user->siteRoles->filter(fn (UserSiteRole $r): bool => $r->role === Role::SiteManager->value && $r->site_id !== null)
                ->map(fn (UserSiteRole $r): string => (string) $r->site_id)->unique()->values()->all());
            if ($siteIds === []) {
                throw Rejection::forbidden('Only the CEO, group admins and site managers receive alerts.');
            }
        }
        $limit = max(1, min(self::MAX, (int) $request->query('limit', '50')));
        $since = CarbonImmutable::now()->subDay();

        $rows = $this->db->table('exceptions as e')->leftJoin('sites as s', 's.id', '=', 'e.site_id')
            ->whereNull('e.deleted_at')->where('e.severity', 'critical')
            ->when($siteIds !== null, fn (Builder $q) => $q->whereIn('e.site_id', $siteIds))
            ->where(fn (Builder $q) => $q->whereIn('e.status', ExceptionRules::ACTIVE_STATUSES)
                ->orWhere(fn (Builder $g) => $g->where('e.kind', 'gate_variance')->where('e.raised_at', '>=', $since)))
            ->orderByDesc('e.raised_at')->limit($limit)
            ->get(['e.id', 'e.site_id', 's.code as site_code', 'e.kind', 'e.title', 'e.detail', 'e.status', 'e.subject_ref', 'e.facts', 'e.raised_at', 'e.decided_at']);

        $data = [];
        foreach ($rows as $r) {
            $facts = is_string($r->facts) ? json_decode($r->facts, true) : null;
            $alert = is_array($facts) && is_array($facts['alert'] ?? null) ? $facts['alert'] : null;
            $data[] = [
                'id' => $r->id, 'siteId' => $r->site_id, 'siteCode' => $r->site_code, 'kind' => $r->kind,
                'title' => is_string($alert['title'] ?? null) ? $alert['title'] : $r->title,
                'detail' => is_string($alert['detail'] ?? null) ? $alert['detail'] : $r->detail,
                'status' => $r->status, 'active' => in_array($r->status, ExceptionRules::ACTIVE_STATUSES, true),
                'subjectRef' => $r->subject_ref, 'raisedAt' => $r->raised_at, 'decidedAt' => $r->decided_at,
            ];
        }

        return new JsonResponse(['data' => $data, 'activeCount' => count(array_filter($data, fn (array $a): bool => $a['active']))]);
    }
}
