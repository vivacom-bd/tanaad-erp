<?php

declare(strict_types=1);

namespace Tanaad\Orders;

use Illuminate\Support\ServiceProvider;
use Tanaad\Identity\Role;
use Tanaad\Orders\Authorization\OrdersAuthorizer;
use Tanaad\Orders\Projectors\OrdersProjector;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Sync\Snapshots;

final class OrdersServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry, Snapshots $snapshots): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $map = [
            'orders.client_saved' => [Commands\RegisterClient::class, Handlers\SaveClientHandler::class],
            'orders.created' => [Commands\CreateOrder::class, Handlers\CreateOrderHandler::class],
            'orders.cash_confirmed' => [Commands\ConfirmOrderCash::class, Handlers\ConfirmOrderCashHandler::class],
            'orders.approved' => [Commands\ApproveOrder::class, Handlers\ApproveOrderHandler::class],
            'orders.held' => [Commands\HoldOrder::class, Handlers\HoldOrderHandler::class],
            'orders.invoiced' => [Commands\IssueInvoice::class, Handlers\IssueInvoiceHandler::class],
            'orders.payment_recorded' => [Commands\RecordPayment::class, Handlers\RecordPaymentHandler::class],
        ];
        foreach ($map as $type => [$command, $handler]) {
            $registry->command($command, $handler, OrdersAuthorizer::class)->eventType($type, Factories\OrdersEnvelopeFactory::class);
        }
        $registry->command(Commands\UpdateClient::class, Handlers\SaveClientHandler::class, OrdersAuthorizer::class);
        // orders.cash_confirmed and cash orders.payment_recorded are booked by Cash as sales cash-in (by type string);
        // the projector also follows dispatch.started/gate_passed/gate_overridden/delivered (by type string).
        $registry->projectors(OrdersProjector::class);

        $sales = [Role::Sales->value, Role::SiteManager->value];
        $snapshots->register('clients', 'clients', siteScoped: false, roles: [...$sales, Role::Cashier->value]);
        $snapshots->register('orders', 'orders', roles: [...$sales, Role::Cashier->value, Role::Storekeeper->value]);
        $snapshots->register('invoices', 'invoices', roles: [...$sales, Role::Cashier->value]);
        $snapshots->register('payments', 'payments', roles: [...$sales, Role::Cashier->value]);
    }
}
