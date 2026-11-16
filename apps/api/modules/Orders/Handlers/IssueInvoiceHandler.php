<?php

declare(strict_types=1);

namespace Tanaad\Orders\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Orders\Commands\IssueInvoice;
use Tanaad\Orders\Support\Sales;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Issues the invoice of an approved (or cash-confirmed) order, once per order: the order row is locked
 * and the invoice number comes from the site's invoice series. The total is the order total; cash already
 * confirmed for a local order is carried as `prepaid`. Fiscal (EFRIS) fields are optional until fiscalisation.
 */
final class IssueInvoiceHandler implements Handler
{
    public function __construct(private readonly NumberSeries $series) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof IssueInvoice) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $orderId = $p->uuid('order_id');
        $invoiceId = $p->optUuid('invoice_id') ?? (string) Str::uuid7();
        $fdn = $p->optStr('efris_fdn', 64);
        $code = $p->optStr('efris_verification_code', 64);
        $p->check();

        $o = DB::table('orders')->where(['id' => $orderId, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($o === null) {
            throw Rejection::notFound('Order', $orderId);
        }
        if ($o->invoice_id !== null || DB::table('invoices')->where('order_id', $orderId)->exists()) {
            throw Rejection::stateConflict("Order {$o->number} is already invoiced.");
        }
        if (! in_array((string) $o->status, Sales::RELEASED, true)) {
            throw Rejection::stateConflict("Order {$o->number} is {$o->status}; only an approved or cash-confirmed order is invoiced.");
        }
        if (DB::table('invoices')->where('id', $invoiceId)->exists()) {
            throw Rejection::duplicateFact("Invoice {$invoiceId} already exists.");
        }
        $currency = (string) $o->currency;
        $total = Sales::money((string) $o->total, $currency);
        $prepaid = Sales::money((string) ($o->cash_amount ?? '0'), $currency);

        return [Event::from($ctx, 'orders.invoiced', [
            'invoice_id' => $invoiceId, 'order_id' => $orderId, 'order_number' => (string) $o->number, 'client_id' => (string) $o->client_id,
            'number' => $this->series->allocate($siteId, Sales::INVOICE_SERIES), 'total' => $total->toFloat(), 'currency' => $currency,
            'fx_reference' => $o->fx_reference === null ? null : (float) $o->fx_reference,
            'prepaid' => $prepaid->toFloat(), 'fully_paid' => $prepaid->isGreaterThanOrEqualTo($total),
            'issued_day' => Sales::businessDay($siteId, $ctx->occurredAt),
            'efris_fdn' => $fdn, 'efris_verification_code' => $code,
        ])];
    }
}
