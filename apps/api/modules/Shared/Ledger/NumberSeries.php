<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Illuminate\Database\ConnectionInterface;

/**
 * Document numbers (delivery note, invoice, voucher, receipt) per site and kind.
 * allocate() locks the series row for the rest of the command transaction, so two
 * concurrent commands can never receive the same number.
 */
final class NumberSeries
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function define(string $siteId, string $kind, string $prefix, int $pad = 5, int $next = 1): void
    {
        $this->db->table('number_series')->upsert(
            [['site_id' => $siteId, 'kind' => $kind, 'prefix' => $prefix, 'pad' => $pad, 'next' => $next]],
            ['site_id', 'kind'],
            ['prefix', 'pad'],
        );
    }

    public function allocate(string $siteId, string $kind): string
    {
        $row = $this->db->table('number_series')
            ->where(['site_id' => $siteId, 'kind' => $kind])
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw Rejection::staleConfig("No number series \"{$kind}\" is defined for this site.");
        }

        $this->db->table('number_series')
            ->where(['site_id' => $siteId, 'kind' => $kind])
            ->update(['next' => (int) $row->next + 1]);

        return $this->format((string) $row->prefix, (int) $row->next, (int) $row->pad);
    }

    public function format(string $prefix, int $n, int $pad): string
    {
        return $prefix.'-'.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
    }
}
