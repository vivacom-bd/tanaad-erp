<?php

declare(strict_types=1);

namespace Tanaad\Sync\Http\Controllers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tanaad\Identity\Access;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Http\CursorPage;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Sync\Snapshots;

/** GET /api/v1/sites/{site}/snapshots/{projection}?since=&limit= — snapshot delta (§8.2); GET .../snapshots?tables= — many at once. */
final class SnapshotController extends ApiController
{
    private const int BATCH_MAX = 80;

    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly ConnectionInterface $db,
        private readonly EventStore $store,
        private readonly Access $access,
    ) {}

    public function __invoke(Request $request, string $site, string $projection): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $page = $this->read($request, $siteId, $projection, CursorPage::from($request), $this->store->lastSeq());

        return new JsonResponse(['projection' => $projection, ...$page]);
    }

    /**
     * GET /api/v1/sites/{site}/snapshots?tables=a,b,c&since=<seq>&cursors=a:12,b:40&limit=<n>
     * Many projections in one request, each role-scoped exactly like the single endpoint. A table the
     * caller may not read (403) or that does not exist (404) is reported inline; the rest still answer.
     */
    public function batch(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $tables = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $request->query('tables', ''))))));
        if ($tables === []) {
            throw Rejection::invalidPayload(['tables' => 'required: a comma-separated list of projections']);
        }
        if (count($tables) > self::BATCH_MAX) {
            throw Rejection::invalidPayload(['tables' => 'at most '.self::BATCH_MAX.' projections per request']);
        }
        $cursors = [];
        foreach (array_filter(explode(',', (string) $request->query('cursors', ''))) as $pair) {
            [$name, $seq] = array_pad(explode(':', $pair, 2), 2, '0');
            $cursors[trim($name)] = max(0, (int) $seq);
        }
        $default = CursorPage::from($request);
        $lastSeq = $this->store->lastSeq();
        $results = [];
        foreach ($tables as $name) {
            try {
                $results[$name] = ['status' => 200, ...$this->read($request, $siteId, $name, new CursorPage($cursors[$name] ?? $default->since, $default->limit), $lastSeq)];
            } catch (Rejection $e) {
                $results[$name] = ['status' => $e->rejectionCode->httpStatus(), 'code' => $e->rejectionCode->value, 'detail' => $e->getMessage()];
            }
        }

        return new JsonResponse(['snapshot_seq' => $lastSeq, 'results' => $results]);
    }

    /** @return array{data: list<array<string, mixed>>, snapshot_seq: int, has_more: bool} */
    private function read(Request $request, string $siteId, string $projection, CursorPage $cursor, int $lastSeq): array
    {
        $entry = $this->snapshots->find($projection);
        if ($entry === null) {
            throw Rejection::notFound('Projection', $projection);
        }
        if ($entry['roles'] !== null) {
            $user = $request->user();
            if (! $user instanceof User || ! $this->access->canAct($user->loadMissing('siteRoles'), $siteId, $entry['roles'])) {
                throw Rejection::forbidden("This role may not read {$projection} here.");
            }
        }

        $query = $this->db->table($entry['table'])->where(function (Builder $q) use ($siteId, $entry): void {
            $q->where('site_id', $siteId);
            if (! $entry['siteScoped']) {
                $q->orWhereNull('site_id');
            }
        });
        $page = $cursor->run($query, $lastSeq);

        return [
            'data' => array_map($this->camel(...), $page['data']),
            'snapshot_seq' => $page['snapshot_seq'],
            'has_more' => $page['has_more'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function camel(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }
            if ($key === 'seq') {
                $value = (int) $value;
            }
            $out[Str::camel($key)] = is_array($value) && ! array_is_list($value) ? $this->camelNested($value) : $value;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function camelNested(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[Str::camel((string) $k)] = is_array($v) && ! array_is_list($v) ? $this->camelNested($v) : $v;
        }

        return $out;
    }
}
