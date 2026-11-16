<?php

declare(strict_types=1);

namespace Tanaad\Orders\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Tanaad\Orders\Commands\IssueInvoice;
use Tanaad\Orders\Commands\RecordPayment;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * Invoices and payments for the orders DispatchSeeder delivered, issued after the goods have left: one invoiced and
 * unpaid, one paid in full, one paid in part (balance owing), and the one delivered today invoiced and unpaid. A
 * payment is a bank transfer of a fraction of the invoice, whole dollars. Run after DispatchSeeder. Ids are uuid5 of
 * names (OrdersSeeder's namespace): a second run adds nothing.
 */
final class OrdersBillingSeeder extends Seeder
{
    private const string TODAY = '2026-11-10';

    /** [order key, invoice days ago, invoice time, payment fraction (null = unpaid), payment days ago] */
    private const array PLAN = [
        ['o_ss1_old', 3, '16:00', null, 0],
        ['o_rw1_paid', 3, '16:20', 1.0, 1],
        ['o_rw1_part', 2, '16:00', 0.5, 0],
        ['o_rw2_deliv', 0, '11:45', null, 0],
    ];

    public function run(CommandBus $bus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'Africa/Kampala'));

        try {
            $done = array_fill_keys(DB::table('events')->where('type', 'like', 'orders.%')->pluck('id')->all(), true);
            $sales = (string) DB::table('users')->where('code', 'u_sales_mats')->value('id');
            $cashier = (string) DB::table('users')->where('code', 'u_cash_mats')->value('id');
            $site = (string) DB::table('sites')->where('code', 'mats')->value('id');
            $send = function (string $name, Command $command) use ($bus, $done): void {
                if (! isset($done[OrdersSeeder::id("event:{$name}")])) {
                    $bus->dispatch($command);
                }
            };
            $ctx = fn (string $name, int $ago, string $at, string $by): CommandContext => new CommandContext(
                $site, $by, null, CarbonImmutable::parse(CarbonImmutable::parse(self::TODAY)->subDays($ago)->toDateString()."T{$at}:00+03:00"), OrdersSeeder::id("event:{$name}"),
            );
            foreach (self::PLAN as [$key, $invoiceAgo, $invoiceAt, $fraction, $payAgo]) {
                $orderId = OrdersSeeder::id($key);
                $status = DB::table('orders')->where('id', $orderId)->value('status');
                if (! in_array($status, ['delivered', 'invoiced'], true)) {
                    continue;
                }
                $send("{$key}:invoice", new IssueInvoice($ctx("{$key}:invoice", $invoiceAgo, $invoiceAt, $sales), ['order_id' => $orderId, 'invoice_id' => OrdersSeeder::id("{$key}:invoice")]));
                $inv = DB::table('invoices')->where('order_id', $orderId)->first(['id', 'total', 'currency']);
                if ($fraction === null || $inv === null) {
                    continue;
                }
                $amount = $fraction >= 1 ? (float) $inv->total : floor((float) $inv->total * $fraction);
                $send("{$key}:pay", new RecordPayment($ctx("{$key}:pay", $payAgo, '11:00', $cashier), [
                    'payment_id' => OrdersSeeder::id("{$key}:payment"), 'invoice_id' => (string) $inv->id, 'amount' => $amount, 'currency' => (string) $inv->currency,
                    'method' => 'Bank transfer', 'reference' => 'TT'.substr(str_replace('-', '', OrdersSeeder::id("{$key}:ref")), 0, 6),
                ]));
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
