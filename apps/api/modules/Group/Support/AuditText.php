<?php

declare(strict_types=1);

namespace Tanaad\Group\Support;

/**
 * Turns a stored event into audit-feed text without leaking payload secrets.
 * Facts are an allow-list per type family; a type outside every listed family shows its type only
 * (identity, sync and anything new), and keys that look secret are dropped even inside a family.
 */
final class AuditText
{
    /** @var array<string, list<string>> */
    private const array FAMILY_KEYS = [
        'cash' => ['entry_id', 'cash_day_id', 'day', 'direction', 'category', 'amount', 'currency', 'opening', 'variance', 'expected_closing', 'counted_closing', 'corrects_entry_id', 'authorized_by'],
        'production' => ['entry_id', 'batch_id', 'waste_id', 'shift_id', 'day', 'shift_kind', 'machine_id', 'product_id', 'stage_code', 'good_units', 'reject_units', 'input_kg', 'output_kg', 'downtime_min', 'downtime_reason', 'below_benchmark', 'benchmark', 'kg', 'waste_kind'],
        'dispatch' => ['dispatch_id', 'order_ref', 'client_name', 'delivery_note_no', 'gate_kg', 'expected_kg', 'variance_pct', 'tolerance_pct', 'gate_status', 'bundles_received', 'discrepancy', 'code'],
        'stock' => ['product_id', 'zone_id', 'from_zone_id', 'to_zone_id', 'bundles', 'qty_units', 'qty_kg', 'kg', 'counted_bundles', 'system_bundles', 'variance', 'cause', 'count_id', 'day', 'code', 'transfer_id'],
        'labels' => ['label_id', 'code', 'kind', 'status', 'product_id'],
        'people' => ['person_id', 'day', 'status', 'shift_kind', 'overtime_hours', 'amount', 'engagement_id', 'end_date', 'settlement_run_id', 'given_on'],
        'group' => ['exception_id', 'kind', 'severity', 'title', 'decision', 'status', 'owner_role', 'day', 'channel', 'brief_id'],
        'spares' => ['breakdown_id', 'machine_id', 'cause', 'part_id', 'qty'],
        'recycling' => ['delivery_id', 'collector_id', 'gross_kg', 'net_kg', 'kg', 'amount'],
        'purchases' => ['purchase_id', 'kg', 'amount', 'status'],
        'config' => ['day', 'amount', 'currency', 'kind', 'code'],
    ];

    private const string SECRET = '/pin|hash|token|secret|password|signature|photo/i';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    public static function facts(string $type, array $payload): array
    {
        $allowed = self::FAMILY_KEYS[strstr($type, '.', true) ?: $type] ?? [];
        $out = [];
        foreach ($allowed as $key) {
            $v = $payload[$key] ?? null;
            if ($v === null || ! is_scalar($v) || preg_match(self::SECRET, $key) === 1) {
                continue;
            }
            $out[$key] = is_string($v) ? mb_substr($v, 0, 200) : $v;
        }

        return $out;
    }

    /** @param array<string, mixed> $f facts from facts() */
    public static function summary(string $type, array $f): string
    {
        $s = fn (string $k): string => isset($f[$k]) ? (string) $f[$k] : '?';

        return match ($type) {
            'cash.entry_recorded' => "Recorded cash {$s('direction')}: {$s('amount')} {$s('currency')} ({$s('category')})",
            'cash.entry_corrected' => "Corrected a cash entry to {$s('amount')} {$s('currency')}",
            'cash.opening_set' => "Set the cash opening for {$s('day')} to {$s('opening')}",
            'cash.day_closed' => "Closed the cash day {$s('day')}, variance {$s('variance')}",
            'cash.day_reopened' => "Reopened the cash day {$s('day')}",
            'production.entry_recorded' => "Logged {$s('good_units')} good units ({$s('output_kg')} kg) for shift {$s('shift_id')}",
            'production.batch_recorded' => "Recorded a mix batch of {$s('output_kg')} kg",
            'production.waste_weighed' => "Weighed {$s('kg')} kg of {$s('waste_kind')} waste",
            'production.shift_closed' => "Closed shift {$s('shift_id')}",
            'dispatch.started' => "Started dispatch {$s('order_ref')}",
            'dispatch.gate_weighed' => "Weighed dispatch {$s('order_ref')} at the gate: {$s('gate_kg')} kg",
            'dispatch.gate_held' => "Held dispatch {$s('order_ref')} at the gate ({$s('variance_pct')}% variance)",
            'dispatch.gate_passed' => "Passed dispatch {$s('order_ref')} at the gate",
            'dispatch.gate_overridden' => "Overrode the gate hold on dispatch {$s('order_ref')}",
            'dispatch.delivered' => "Delivered {$s('order_ref')} to {$s('client_name')}",
            'stock.counted' => "Counted stock: {$s('counted_bundles')} bundles against {$s('system_bundles')} on the system",
            'stock.adjusted' => "Adjusted stock by {$s('bundles')} bundles ({$s('cause')})",
            'stock.count_reopened' => 'Reopened a stock count',
            'stock.received' => "Received {$s('bundles')} bundles into stock",
            'stock.moved' => "Moved {$s('bundles')} bundles between zones",
            'group.exception_raised' => "Raised a {$s('severity')} exception: {$s('title')}",
            'group.exception_decided' => "Decided an exception: {$s('decision')}",
            'group.brief_sent' => "Morning brief for {$s('day')} ({$s('channel')})",
            'people.attendance_marked' => "Marked attendance: {$s('status')} on {$s('day')}",
            'people.attendance_corrected' => "Corrected attendance to {$s('status')} on {$s('day')}",
            'people.settlement_approved' => 'Approved a settlement run',
            'people.settlement_cancelled' => 'Cancelled a settlement run',
            'people.payout_recorded' => "Recorded a payout of {$s('amount')}",
            default => self::humanize($type),
        };
    }

    /** "cash.day_reopened" becomes "Cash day reopened". */
    public static function humanize(string $type): string
    {
        $t = str_replace(['.', '_'], ' ', $type);

        return ucfirst(trim($t));
    }

    /**
     * normal | edit | reopen | override | variance (the prototype's audit flags). Holds count as variance.
     *
     * @param  array<string, mixed>  $f
     */
    public static function flag(string $type, array $f, bool $corrects): string
    {
        $nonZero = fn (string $k): bool => isset($f[$k]) && is_numeric($f[$k]) && (float) $f[$k] !== 0.0;

        return match (true) {
            str_ends_with($type, '_reopened') => 'reopen',
            str_ends_with($type, '_overridden') => 'override',
            $corrects || str_ends_with($type, '_corrected') => 'edit',
            $type === 'dispatch.gate_held', $type === 'group.exception_decided' && ($f['decision'] ?? null) === 'hold' => 'variance',
            in_array($type, ['cash.day_closed', 'stock.counted'], true) && $nonZero('variance') => 'variance',
            default => 'normal',
        };
    }
}
