<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * Per-event integrity checksum: HMAC-SHA256 over the facts of an event (everything but seq,
 * received_at and batch_id), keyed by tanaad.ledger.checksum_key (APP_KEY when unset). Someone
 * with only database access cannot edit a row and recompute its checksum. It is NOT a chain:
 * a deleted row is only visible as a seq gap, which ledger:verify reports.
 */
final class LedgerChecksum
{
    public static function forEvent(Event $e): string
    {
        return self::compute($e->id, $e->siteId, $e->type, $e->version, $e->actorId, $e->deviceId,
            $e->occurredAt->format('Y-m-d H:i:s'), $e->payload, $e->correctsEventId, $e->reason);
    }

    /** @param object{id: string, type: string, site_id: ?string, actor_id: ?string, device_id: ?string, occurred_at: string, payload: string, version: int|string, corrects_event_id: ?string, reason: ?string} $row */
    public static function forRow(object $row): string
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

        return self::compute($row->id, $row->site_id, $row->type, (int) $row->version, $row->actor_id, $row->device_id,
            substr($row->occurred_at, 0, 19), $payload, $row->corrects_event_id, $row->reason);
    }

    /** @param array<mixed> $payload */
    private static function compute(string $id, ?string $site, string $type, int $version, ?string $actor, ?string $device, string $occurredAt, array $payload, ?string $corrects, ?string $reason): string
    {
        $canonical = json_encode(
            [$id, $site, $type, $version, $actor, $device, $occurredAt, self::normalise($payload), $corrects, $reason],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash_hmac('sha256', $canonical, self::key());
    }

    /** Sorted keys, integral floats as ints: MySQL's JSON type reorders keys and may reshape numbers. */
    private static function normalise(mixed $value): mixed
    {
        if (is_object($value)) {
            // e.g. a payload's `(object) $facts`: hash it as the JSON it is stored as, so append and verify agree.
            $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = array_map(self::normalise(...), $value);
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    private static function key(): string
    {
        $key = config('tanaad.ledger.checksum_key');
        $key = is_string($key) && $key !== '' ? $key : (string) config('app.key');

        return $key !== '' ? $key : 'tanaad-unkeyed';
    }
}
