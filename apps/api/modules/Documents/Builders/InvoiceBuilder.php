<?php

declare(strict_types=1);

namespace Tanaad\Documents\Builders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Schema;
use Tanaad\Documents\Support\Company;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Invoice (kind invoice) or client statement (kind statement) once the Orders module has created its tables.
 * Until then available() is false and the controller answers 404 unknown kind. Reads rows as stored; the
 * shape is finalised with the Orders read model.
 */
final class InvoiceBuilder implements DocumentBuilder
{
    private const array ROLES = [Role::SiteManager->value, Role::Sales->value, Role::Cashier->value];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Company $company,
        private readonly bool $statement = false,
    ) {}

    public function available(): bool
    {
        return Schema::hasTable('invoices') && ($this->statement ? Schema::hasColumn('invoices', 'client_id') : true);
    }

    public function build(User $user, string $id): array
    {
        if ($this->statement) {
            $rows = $this->db->table('invoices')->where('client_id', $id)->orderBy('id')->get();
            if ($rows->isEmpty()) {
                throw Rejection::notFound('Client', $id);
            }
            foreach ($rows as $r) {
                $this->assertSite($user, (string) ($r->site_id ?? ''));
            }
            $siteId = (string) ($rows->first()->site_id ?? '');

            $name = $this->db->table('clients')->where('id', $id)->value('name');

            return [...$this->company->forSite($siteId), 'client_id' => $id, 'client_name' => $name, 'invoices' => $rows->map(fn (object $r): array => self::public($r))->values()->all()];
        }
        $invoice = $this->db->table('invoices')->where('id', $id)->first();
        if ($invoice === null) {
            throw Rejection::notFound('Invoice', $id);
        }
        $this->assertSite($user, (string) ($invoice->site_id ?? ''));
        $lines = $this->orderLines((string) ($invoice->order_id ?? ''));

        return [...$this->company->forSite((string) ($invoice->site_id ?? '')), 'invoice' => self::public($invoice), 'lines' => $lines];
    }

    /** Internal ledger columns (event_id, seq) and soft-delete bookkeeping never leave the API. */
    private const array INTERNAL = ['event_id', 'seq', 'deleted_at'];

    /** @return array<string, mixed> */
    private static function public(?object $row): array
    {
        return array_diff_key((array) $row, array_flip(self::INTERNAL));
    }

    /**
     * The invoice bills its order's lines (stored as JSON on the order), with the product name added.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function orderLines(string $orderId): array
    {
        $json = $orderId === '' ? null : $this->db->table('orders')->where('id', $orderId)->value('lines');
        $lines = is_string($json) ? (json_decode($json, true) ?: []) : [];
        $names = $lines === [] ? collect() : $this->db->table('products')->whereIn('id', array_column($lines, 'product_id'))->pluck('internal_name', 'id');

        return array_map(fn (array $l): array => [...$l, 'product_name' => $names[$l['product_id'] ?? ''] ?? null], $lines);
    }

    private function assertSite(User $user, string $siteId): void
    {
        if ($siteId === '' || ! $this->access->canAct($user, $siteId, self::ROLES)) {
            throw Rejection::forbidden('You may not print this document.');
        }
    }
}
