<?php

declare(strict_types=1);

namespace Tanaad\Documents\Support;

use Illuminate\Database\ConnectionInterface;

/** The issuing site and its company profile (Config), read by table name. */
final class Company
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array{site: array<string, mixed>, company: array<string, mixed>} */
    public function forSite(string $siteId): array
    {
        $site = $this->db->table('sites')->where('id', $siteId)->first(['id', 'code', 'name']);
        $profile = $this->db->table('company_profiles')->where('site_id', $siteId)->whereNull('deleted_at')->first();

        return [
            'site' => ['id' => $siteId, 'code' => $site->code ?? null, 'name' => $site->name ?? null],
            'company' => [
                'name' => $site->name ?? null, 'address' => $profile->address ?? '', 'phone' => $profile->phone ?? '', 'email' => $profile->email ?? '',
                'tin' => $profile->tin ?? '', 'bank_details' => $profile->bank_details ?? '', 'mobile_money' => $profile->mobile_money ?? '',
                'vat_rate_pct' => (float) ($profile->vat_rate_pct ?? 0),
            ],
        ];
    }
}
