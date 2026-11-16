<?php

declare(strict_types=1);

namespace Tanaad\Group\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * The morning brief for a date: yesterday per site, as structured facts and as plain lines.
 * A query only; MorningBriefCommand sends it through the SendBrief command.
 */
final class MorningBrief
{
    private const string NAMESPACE = 'b6f0a8de-3d1e-5c8a-9a52-2f0c1b7a6e41';

    public function __construct(private readonly ConnectionInterface $db, private readonly ExceptionRules $rules) {}

    /** The brief id and event id for a date: one brief per date, forever. */
    public static function idFor(string $date): string
    {
        return Uuid::uuid5(self::NAMESPACE, "morning-brief|{$date}")->toString();
    }

    /** @return array{day: string, for: string, title: string, sites: list<array<string, mixed>>, lines: list<string>} */
    public function build(string $date): array
    {
        $tz = $this->rules->timezone();
        $for = CarbonImmutable::parse($date, $tz)->subDay();
        $day = $for->toDateString();
        $from = $for->startOfDay()->utc()->format('Y-m-d H:i:s.u');
        $to = $for->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s.u');
        $title = 'Morning brief for '.CarbonImmutable::parse($date)->format('D j M Y').': '.$for->format('D j M').' at a glance';

        $sites = [];
        foreach ($this->db->table('sites')->whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'name', 'currency_primary']) as $s) {
            $sites[] = $this->site((string) $s->id, (string) $s->code, (string) $s->name, (string) $s->currency_primary, $day, $from, $to);
        }

        $lines = [$title, ''];
        foreach ($sites as $s) {
            $lines[] = $s['name'];
            $o = $s['production'];
            $lines[] = '  Output: '.$o['goodUnits'].' good units ('.$this->kg($o['outputKg']).' kg)'.($o['benchmarkUnits'] > 0 ? ", {$o['benchmarkPct']}% of benchmark" : ', no benchmark logged');
            $c = $s['cash'];
            $lines[] = '  Cash: '.($c['variance'] === null ? 'day not closed' : "variance {$c['variance']} {$s['currency']}");
            $lines[] = "  Exceptions: {$s['exceptions']['raised']} raised, {$s['exceptions']['open']} still open";
            $lines[] = "  Gate holds: {$s['gateHolds']}; deliveries: {$s['delivered']}".($s['newOrders'] === null ? '' : "; new orders: {$s['newOrders']}");
            $lines[] = '';
        }
        array_pop($lines);

        return ['day' => $date, 'for' => $day, 'title' => $title, 'sites' => $sites, 'lines' => $lines];
    }

    /** @return array<string, mixed> */
    private function site(string $id, string $code, string $name, string $currency, string $day, string $from, string $to): array
    {
        $p = $this->db->table('production_entries')->where('site_id', $id)->where('day', $day)->whereNull('deleted_at')
            ->selectRaw('coalesce(sum(good_units),0) as good, coalesce(sum(output_kg),0) as out_kg, coalesce(sum(case when benchmark is not null then good_units else 0 end),0) as bgood, coalesce(sum(benchmark),0) as bench')->first();
        $p = (array) $p;
        $bench = (float) ($p['bench'] ?? 0);
        $cash = $this->db->table('cash_days')->where('site_id', $id)->where('day', $day)->whereNull('deleted_at')->first(['status', 'variance']);
        $count = fn (string $type): int => $this->db->table('events')->where('site_id', $id)->where('type', $type)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->count();

        return [
            'siteId' => $id, 'siteCode' => $code, 'name' => $name, 'currency' => $currency,
            'production' => [
                'goodUnits' => (int) ($p['good'] ?? 0), 'outputKg' => (float) ($p['out_kg'] ?? 0), 'benchmarkUnits' => $bench,
                'benchmarkPct' => $bench > 0 ? (int) round(((float) ($p['bgood'] ?? 0)) / $bench * 100) : null,
            ],
            'cash' => ['status' => $cash?->status, 'variance' => $cash?->variance === null ? null : (float) $cash->variance],
            'exceptions' => [
                'raised' => $this->db->table('exceptions')->where('site_id', $id)->where('raised_at', '>=', $from)->where('raised_at', '<', $to)->count(),
                'open' => $this->db->table('exceptions')->where('site_id', $id)->whereIn('status', ExceptionRules::ACTIVE_STATUSES)->count(),
            ],
            'gateHolds' => $count('dispatch.gate_held'),
            'delivered' => $count('dispatch.delivered'),
            'newOrders' => $this->newOrders($id, $day),
        ];
    }

    /** Orders taken on the site's business day (orders.day), null when Orders is not installed. */
    private function newOrders(string $id, string $day): ?int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumns('orders', ['site_id', 'day'])) {
            return null;
        }

        return $this->db->table('orders')->where('site_id', $id)->where('day', $day)->whereNull('deleted_at')->count();
    }

    private function kg(float $kg): string
    {
        return rtrim(rtrim(number_format($kg, 3, '.', ','), '0'), '.');
    }
}
