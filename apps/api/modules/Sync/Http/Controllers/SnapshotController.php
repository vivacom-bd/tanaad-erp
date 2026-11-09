<?php

declare(strict_types=1);

namespace Tanaad\Sync\Http\Controllers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Http\CursorPage;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Sync\Snapshots;

/** GET /api/v1/sites/{site}/snapshots/{projection}?since=&limit= — snapshot delta (§8.2). */
final class SnapshotController extends ApiController
{
    public function __construct(
        private readonly Snapshots $snapshots,
        private readonly ConnectionInterface $db,
        private readonly EventStore $store,
    ) {}

    public function __invoke(Request $request, string $site, string $projection): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $entry = $this->snapshots->find($projection);
        if ($entry === null) {
            throw Rejection::notFound('Projection', $projection);
        }

        $query = $this->db->table($entry['table'])->where(function (Builder $q) use ($siteId, $entry): void {
            $q->where('site_id', $siteId);
            if (! $entry['siteScoped']) {
                $q->orWhereNull('site_id');
            }
        });
        $page = CursorPage::from($request)->run($query, $this->store->lastSeq());

        return new JsonResponse([
            'projection' => $projection,
            'data' => array_map($this->camel(...), $page['data']),
            'snapshot_seq' => $page['snapshot_seq'],
            'has_more' => $page['has_more'],
        ]);
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
