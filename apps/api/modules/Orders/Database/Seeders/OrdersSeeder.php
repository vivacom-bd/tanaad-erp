<?php

declare(strict_types=1);

namespace Tanaad\Orders\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tanaad\Orders\Commands\ApproveOrder;
use Tanaad\Orders\Commands\ConfirmOrderCash;
use Tanaad\Orders\Commands\CreateOrder;
use Tanaad\Orders\Commands\HoldOrder;
use Tanaad\Orders\Commands\IssueInvoice;
use Tanaad\Orders\Commands\RegisterClient;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\DayLocks;

/**
 * The prototype's eight clients and fourteen orders across the lifecycle, through the command bus: local orders
 * awaiting cash and cash-confirmed (one invoiced, prepaid), export orders approved (several small ones that
 * DispatchSeeder loads and moves), one held, one awaiting approval, and the Juba order that takes its client over
 * the credit limit (an open credit_over_limit for the CEO). Prices come from Config, so the seeder never states one.
 * Run before DispatchSeeder (which dispatches the approved orders) and OrdersBillingSeeder (which invoices and
 * collects on the ones that were delivered); needs ConfigSeeder's sales_order/invoice series. Ids are uuid5 of names:
 * a second run adds nothing.
 */
final class OrdersSeeder extends Seeder
{
    public const string NAMESPACE = 'a3c51e7d-94b2-5f06-8d3a-61e0f2b7c948';

    private const string TODAY = '2026-11-10';

    /** [key, name, market, channel, currency, credit limit, credit days, contact, whatsapp] as in the prototype seed */
    private const array CLIENTS = [
        ['c_kla1', 'Owino Market Traders', 'Uganda', 'local', 'UGX', 0, 0, 'Mama Nalongo', '+256 772 110 334'],
        ['c_kla2', 'Jinja Road Wholesalers', 'Uganda', 'local', 'UGX', 0, 0, 'Mr. Ssekandi', '+256 701 445 920'],
        ['c_kla3', 'Mbarara Home Supplies', 'Uganda', 'local', 'UGX', 0, 0, 'Doreen K.', '+256 782 330 118'],
        ['c_rw1', 'Kigali Home & Mat Traders', 'Rwanda', 'export', 'USD', 40000, 30, 'Jean-Paul H.', '+250 788 402 115'],
        ['c_rw2', 'Nyabugogo Distributors', 'Rwanda', 'export', 'USD', 25000, 30, 'Claudine U.', '+250 722 918 207'],
        ['c_ss1', 'Juba Konyo-Konyo Traders', 'South Sudan', 'export', 'USD', 30000, 45, 'Deng M.', '+211 912 334 870'],
        ['c_bag1', 'Nakasero Supermarkets', 'Uganda', 'local', 'UGX', 0, 0, 'Procurement', '+256 752 006 318'],
        ['c_rec1', 'East Africa Polymers Ltd', 'Uganda', 'local', 'UGX', 0, 0, 'Plant buyer', '+256 759 220 441'],
    ];

    /**
     * [key, site, client, days ago, lines [product code, bundles], steps]; steps: cash, approve, hold, invoice (prepaid local only).
     * Ordered oldest first so the credit check sees the balances the earlier orders leave. The first seven (and the held
     * and ready ones) are small enough to be loaded from the mats stock by DispatchSeeder, which then moves them; the
     * invoices and payments of the dispatched ones follow in OrdersBillingSeeder, after the goods have left.
     *
     * @var list<array{string, string, string, int, list<array{string, int}>, list<string>}>
     */
    public const array ORDERS = [
        ['o_ss1_old', 'mats', 'c_ss1', 9, [['p_rihlaat', 4], ['p_domona', 3]], ['approve']],
        ['o_rw1_paid', 'mats', 'c_rw1', 8, [['p_rihlaat', 3], ['p_domona', 3], ['p_27m', 2]], ['approve']],
        ['o_rw1_part', 'mats', 'c_rw1', 7, [['p_rihlaat', 4], ['p_27m', 3]], ['approve']],
        ['o_kla1_paid', 'mats', 'c_kla1', 6, [['p_rihlaat', 12], ['p_sajaada_l', 8]], ['cash', 'invoice']],
        ['o_rec1_cash', 'recycling', 'c_rec1', 2, [['p_hdpe_nat', 200]], ['cash']],
        ['o_rw2_road', 'mats', 'c_rw2', 1, [['p_sajaada_l', 4]], ['approve']],
        ['o_rw1_hold', 'mats', 'c_rw1', 1, [['p_domona', 3]], ['approve']],
        ['o_ss1_override', 'mats', 'c_ss1', 1, [['p_27m', 5]], ['approve']],
        ['o_rw2_deliv', 'mats', 'c_rw2', 1, [['p_sajaada_h', 4]], ['approve']],
        ['o_rw1_ready', 'mats', 'c_rw1', 1, [['p_rihlaat', 10], ['p_domona', 5]], ['approve']],
        ['o_kla2_wait', 'mats', 'c_kla2', 0, [['p_rihlaat', 12], ['p_sajaada_l', 8]], []],
        ['o_bag1_wait', 'bags', 'c_bag1', 0, [['p_bag_medium_black', 40], ['p_bag_large_white', 25]], []],
        ['o_rw2_held', 'mats', 'c_rw2', 0, [['p_27m', 40]], ['hold']],
        ['o_ss1_juba', 'mats', 'c_ss1', 0, [['p_sajaada_h', 260], ['p_domona', 320]], []],
    ];

    private CommandBus $bus;

    /** @var array<string, true> */
    private array $done = [];

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    public function run(CommandBus $bus, DayLocks $locks): void
    {
        $this->bus = $bus;
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            $this->done = array_fill_keys(DB::table('events')->where('type', 'like', 'orders.%')->pluck('id')->all(), true);
            $sites = DB::table('sites')->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
            $user = fn (string $code): string => (string) DB::table('users')->where('code', $code)->value('id');
            $sales = ['mats' => $user('u_sales_mats'), 'bags' => $user('u_sales_bags'), 'recycling' => $user('u_sales_rec')];
            $cashier = ['mats' => $user('u_cash_mats'), 'bags' => $user('u_cash_bags'), 'recycling' => $user('u_cash_rec')];
            $ceo = $user('u_ceo1');

            foreach (self::CLIENTS as [$key, $name, $market, $channel, $currency, $limit, $days, $contact, $whatsapp]) {
                $this->send("client:{$key}", new RegisterClient($this->ctx($sites['mats'], $ceo, 0, '08:00', "client:{$key}"), [
                    'client_id' => self::id($key), 'name' => $name, 'country' => $market, 'channel' => $channel, 'currency_pref' => $currency,
                    'credit_limit' => $limit, 'credit_days' => $days, 'contact' => $contact, 'whatsapp' => $whatsapp,
                ]));
            }
            $products = DB::table('products')->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
            foreach (self::ORDERS as [$key, $site, $client, $ago, $lines, $steps]) {
                $siteId = $sites[$site];
                $orderId = self::id($key);
                $this->send("{$key}:create", new CreateOrder($this->ctx($siteId, $sales[$site], $ago, '09:30', "{$key}:create"), [
                    'order_id' => $orderId, 'client_id' => self::id($client),
                    'lines' => array_map(fn (array $l): array => ['product_id' => $products[$l[0]], 'bundles' => $l[1]], $lines),
                ]));
                $stepAgo = $ago;
                foreach ($steps as $step) {
                    $what = $step;
                    $ctx = fn (int $daysAgo, string $at, string $by): CommandContext => $this->ctx($siteId, $by, $daysAgo, $at, "{$key}:{$step}");
                    if ($what === 'approve') {
                        $this->send("{$key}:{$step}", new ApproveOrder($ctx($ago, '09:50', $ceo), ['order_id' => $orderId]));
                    } elseif ($what === 'cash') {
                        $stepAgo = $this->openCashDay($locks, $siteId, $ago);
                        $this->send("{$key}:{$step}", new ConfirmOrderCash($ctx($stepAgo, '10:00', $cashier[$site]), [
                            'order_id' => $orderId, 'amount' => (float) DB::table('orders')->where('id', $orderId)->value('total'),
                        ]));
                    } elseif ($what === 'invoice') {
                        $this->send("{$key}:{$step}", new IssueInvoice($ctx($stepAgo, $stepAgo === $ago ? '16:00' : '11:30', $sales[$site]), [
                            'order_id' => $orderId, 'invoice_id' => self::id("{$key}:invoice"),
                        ]));
                    } elseif ($what === 'hold') {
                        $this->send("{$key}:{$step}", new HoldOrder($ctx($ago, '10:10', $ceo), ['order_id' => $orderId, 'reason' => 'Held until the client confirms how this order will be settled']));
                    }
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** The planned day, or the next one whose cash book is still open (Cash may have closed past days). */
    private function openCashDay(DayLocks $locks, string $siteId, int $ago): int
    {
        // Sundays are rest days: no cash book is kept, so a cash order waits for the next working day.
        while ($ago > 0 && (CarbonImmutable::parse(self::TODAY)->subDays($ago)->dayOfWeek === 0 || $locks->isLocked($siteId, 'cash', CarbonImmutable::parse(self::TODAY)->subDays($ago)->toDateString()))) {
            $ago--;
        }

        return $ago;
    }

    private function ctx(string $siteId, string $actor, int $daysAgo, string $at, string $name): CommandContext
    {
        return new CommandContext($siteId, $actor, null, CarbonImmutable::parse(CarbonImmutable::parse(self::TODAY)->subDays($daysAgo)->toDateString()."T{$at}:00+03:00"), self::id("event:{$name}"));
    }

    private function send(string $name, Command $command): void
    {
        if (isset($this->done[self::id("event:{$name}")])) {
            return;
        }
        $this->bus->dispatch($command);
    }
}
