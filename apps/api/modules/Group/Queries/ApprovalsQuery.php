<?php

declare(strict_types=1);

namespace Tanaad\Group\Queries;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Tanaad\Group\Models\BusinessException;
use Tanaad\Group\Support\ExceptionRules;

/** What waits for a group decision: open and held exceptions, plus orders awaiting approval when Orders is installed. */
final class ApprovalsQuery
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array{exceptions: list<array<string, mixed>>, orders: list<array<string, mixed>>, counts: array<string, int>} */
    public function build(): array
    {
        $codes = $this->db->table('sites')->pluck('code', 'id')->all();
        $exceptions = BusinessException::query()->whereIn('status', ExceptionRules::ACTIVE_STATUSES)
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderBy('raised_at')->orderBy('seq')->limit(500)->get()
            ->map(fn (BusinessException $e): array => [
                ...ExceptionQuery::shape($e, $codes[$e->site_id] ?? null),
                'needsGroup' => in_array($e->owner_role, ExceptionRules::GROUP_OWNERS, true),
            ])->all();
        $exceptions = array_values($exceptions);
        $orders = $this->orders($codes);
        $count = fn (string $s): int => count(array_filter($exceptions, fn (array $e): bool => $e['severity'] === $s));

        return ['exceptions' => $exceptions, 'orders' => $orders, 'counts' => [
            'exceptions' => count($exceptions), 'orders' => count($orders),
            'critical' => $count('critical'), 'warning' => $count('warning'), 'info' => $count('info'),
        ]];
    }

    /**
     * Orders awaiting the credit desk (Orders' status `awaiting_approval`, plus `held` ones that may still be approved).
     *
     * @param  array<string, string>  $codes
     * @return list<array<string, mixed>>
     */
    private function orders(array $codes): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }
        $rows = $this->db->table('orders')->whereIn('status', ['awaiting_approval', 'held'])->whereNull('deleted_at')
            ->orderBy('at')->orderBy('seq')->limit(200)
            ->get(['id', 'site_id', 'number', 'client_id', 'client_name', 'channel', 'status', 'total', 'currency', 'over_limit', 'hold_reason', 'day', 'at']);

        return array_values($rows->map(fn ($o): array => [
            'id' => (string) $o->id, 'siteId' => (string) $o->site_id, 'siteCode' => $codes[$o->site_id] ?? null,
            'number' => (string) $o->number, 'clientId' => (string) $o->client_id, 'clientName' => (string) $o->client_name,
            'channel' => (string) $o->channel, 'status' => (string) $o->status, 'total' => (float) $o->total, 'currency' => (string) $o->currency,
            'overLimit' => (bool) $o->over_limit, 'holdReason' => $o->hold_reason, 'day' => (string) $o->day, 'at' => (string) $o->at,
        ])->all());
    }
}
