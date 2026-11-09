<?php

declare(strict_types=1);

namespace Tanaad\Shared\Http;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Cursor pagination by seq: `?since=<seq>&limit=<n>` returns rows with seq > since in
 * seq order plus the high-water mark the client should store (§8.2, §16).
 */
final readonly class CursorPage
{
    public function __construct(public int $since, public int $limit) {}

    public static function from(Request $request, int $default = 1000, int $max = 1000): self
    {
        $since = max(0, (int) $request->query('since', '0'));
        $limit = (int) $request->query('limit', (string) $default);

        return new self($since, max(1, min($max, $limit)));
    }

    /**
     * @return array{data: list<array<string, mixed>>, snapshot_seq: int, has_more: bool}
     */
    public function run(Builder $query, int $snapshotSeq): array
    {
        $rows = $query->where('seq', '>', $this->since)->orderBy('seq')->limit($this->limit + 1)->get();
        $hasMore = $rows->count() > $this->limit;
        $rows = $rows->take($this->limit);
        $data = array_values($rows->map(fn (object $r): array => get_object_vars($r))->values()->all());
        $last = $rows->last();
        $cursor = $hasMore && $last !== null ? (int) $last->seq : $snapshotSeq;

        return ['data' => $data, 'snapshot_seq' => $cursor, 'has_more' => $hasMore];
    }
}
