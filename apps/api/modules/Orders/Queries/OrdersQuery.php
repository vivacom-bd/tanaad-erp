<?php

declare(strict_types=1);

namespace Tanaad\Orders\Queries;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/** Read shapes (prototype camelCase): clients, orders, invoices, payments, client balance, receivables, statement. */
final class OrdersQuery
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array<string, mixed>> */
    public function clients(): array
    {
        return array_values($this->db->table('clients')->whereNull('deleted_at')->orderBy('name')->get()->map(fn (\stdClass $r): array => self::client($r))->all());
    }

    /** @return list<array<string, mixed>> */
    public function orders(string $siteId, ?string $status): array
    {
        $q = $this->db->table('orders')->where('site_id', $siteId)->whereNull('deleted_at');
        if ($status !== null) {
            $q->where('status', $status);
        }

        return array_values($q->orderByDesc('seq')->get()->map(fn (\stdClass $r): array => self::order($r))->all());
    }

    /** @return list<array<string, mixed>> */
    public function invoices(string $siteId): array
    {
        return array_values($this->db->table('invoices')->where('site_id', $siteId)->whereNull('deleted_at')->orderByDesc('seq')->get()
            ->map(fn (\stdClass $r): array => self::invoice($r))->all());
    }

    /** @return list<array<string, mixed>> */
    public function payments(string $siteId): array
    {
        return array_values($this->db->table('payments')->where('site_id', $siteId)->whereNull('deleted_at')->orderByDesc('seq')->get()
            ->map(fn (\stdClass $r): array => self::payment($r))->all());
    }

    /**
     * As the prototype's clientBalance: invoiced − paid (cash confirmed on an invoiced order counts as paid),
     * open invoices oldest first, and the age in days of the oldest.
     *
     * @return array<string, mixed>
     */
    public function balance(string $clientId, ?string $siteId, string $today): array
    {
        $invoices = $this->db->table('invoices')->where('client_id', $clientId)->whereNull('deleted_at')
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId))->orderBy('issued_at')->get();
        $invoiced = BigDecimal::zero();
        $paid = BigDecimal::zero();
        $open = [];
        foreach ($invoices as $i) {
            $invoiced = $invoiced->plus((string) $i->total);
            $paid = $paid->plus((string) $i->paid_amount);
            if (BigDecimal::of((string) $i->total)->isGreaterThan((string) $i->paid_amount)) {
                $open[] = $i;
            }
        }
        $last = $this->db->table('payments')->where('client_id', $clientId)->whereNull('deleted_at')
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId))->orderByDesc('at')->first();
        $age = $open === [] ? 0 : (int) CarbonImmutable::parse(substr((string) $open[0]->issued_day, 0, 10))->diffInDays(CarbonImmutable::parse($today));

        return [
            'invoiced' => $invoiced->toFloat(), 'paid' => $paid->toFloat(), 'balance' => $invoiced->minus($paid)->toFloat(),
            'openInvoices' => array_map(fn (\stdClass $r): array => self::invoice($r), $open), 'ageDays' => $age,
            'lastPayment' => $last === null ? null : self::payment($last),
        ];
    }

    /**
     * Export clients that owe money (the prototype's receivables), optionally only those with orders at a site.
     *
     * @return list<array<string, mixed>>
     */
    public function receivables(?string $siteId, string $today): array
    {
        $out = [];
        foreach ($this->db->table('clients')->where('channel', 'export')->whereNull('deleted_at')->orderBy('name')->get() as $c) {
            if ($siteId !== null && ! $this->db->table('orders')->where(['client_id' => $c->id, 'site_id' => $siteId])->exists()) {
                continue;
            }
            $b = $this->balance((string) $c->id, $siteId, $today);
            if ($b['balance'] > 0) {
                $out[] = ['client' => self::client($c), ...$b];
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function statement(string $clientId, ?string $siteId, string $today): ?array
    {
        $c = $this->db->table('clients')->where('id', $clientId)->whereNull('deleted_at')->first();
        if ($c === null) {
            return null;
        }
        $scope = fn (Builder $q) => $siteId === null ? $q : $q->where('site_id', $siteId);
        $invoices = $scope($this->db->table('invoices')->where('client_id', $clientId)->whereNull('deleted_at'))->orderBy('issued_at')->get();
        $payments = $scope($this->db->table('payments')->where('client_id', $clientId)->whereNull('deleted_at'))->orderBy('at')->get();

        return [
            'client' => self::client($c),
            'asOf' => $today,
            ...$this->balance($clientId, $siteId, $today),
            'invoices' => array_values($invoices->map(fn (\stdClass $r): array => [...self::invoice($r),
                'dueDay' => CarbonImmutable::parse(substr((string) $r->issued_day, 0, 10))->addDays((int) $c->credit_days)->toDateString()])->all()),
            'payments' => array_values($payments->map(fn (\stdClass $r): array => self::payment($r))->all()),
        ];
    }

    /** @return array<string, mixed> */
    public static function client(\stdClass $r): array
    {
        return [
            'id' => (string) $r->id, 'name' => (string) $r->name, 'country' => (string) $r->country, 'channel' => (string) $r->channel,
            'whatsapp' => (string) $r->whatsapp, 'currencyPref' => (string) $r->currency_pref, 'creditLimit' => (float) $r->credit_limit,
            'creditDays' => (int) $r->credit_days, 'contact' => (string) $r->contact, 'active' => (bool) $r->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function order(\stdClass $r): array
    {
        $lines = json_decode((string) $r->lines, true);

        return [
            'id' => (string) $r->id, 'number' => (string) $r->number, 'siteId' => (string) $r->site_id, 'clientId' => (string) $r->client_id,
            'clientName' => (string) $r->client_name, 'channel' => (string) $r->channel, 'status' => (string) $r->status, 'currency' => (string) $r->currency,
            'fxReference' => $r->fx_reference === null ? null : (float) $r->fx_reference, 'total' => (float) $r->total, 'overLimit' => (bool) $r->over_limit,
            'lines' => array_map(fn (array $l): array => ['productId' => $l['product_id'], 'bundles' => $l['bundles'], 'unitPrice' => $l['unit_price'], 'amount' => $l['amount']],
                is_array($lines) ? $lines : []),
            'createdAt' => (string) $r->at, 'day' => substr((string) $r->day, 0, 10), 'approvedBy' => $r->approved_by, 'approvedAt' => $r->approved_at,
            'cashConfirmedAt' => $r->cash_confirmed_at, 'notes' => $r->notes, 'holdReason' => $r->hold_reason, 'invoiceId' => $r->invoice_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(\stdClass $r): array
    {
        return [
            'id' => (string) $r->id, 'orderId' => (string) $r->order_id, 'orderNumber' => (string) $r->order_number, 'clientId' => (string) $r->client_id,
            'number' => (string) $r->number, 'total' => (float) $r->total, 'currency' => (string) $r->currency, 'paid' => (float) $r->paid_amount,
            'balance' => BigDecimal::of((string) $r->total)->minus((string) $r->paid_amount)->toFloat(),
            'issuedAt' => (string) $r->issued_at, 'issuedDay' => substr((string) $r->issued_day, 0, 10),
            'efrisFdn' => $r->efris_fdn, 'efrisVerificationCode' => $r->efris_verification_code,
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(\stdClass $r): array
    {
        return [
            'id' => (string) $r->id, 'clientId' => (string) $r->client_id, 'invoiceId' => (string) $r->invoice_id, 'amount' => (float) $r->amount,
            'currency' => (string) $r->currency, 'method' => (string) $r->method, 'reference' => (string) $r->reference, 'at' => (string) $r->at,
            'day' => substr((string) $r->day, 0, 10),
        ];
    }
}
