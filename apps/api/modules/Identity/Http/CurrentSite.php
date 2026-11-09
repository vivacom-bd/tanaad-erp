<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http;

use Illuminate\Http\Request;
use Tanaad\Config\Models\Site;
use Tanaad\Shared\Ledger\Rejection;

/** Resolves the `{site}` route segment (code or id) to a Site and remembers it on the request. */
final class CurrentSite
{
    public static function resolve(Request $request): Site
    {
        $cached = $request->attributes->get('site');
        if ($cached instanceof Site) {
            return $cached;
        }
        $param = $request->route('site');
        $param = is_string($param) ? $param : '';
        $site = Site::query()->where('code', $param)
            ->when(\Illuminate\Support\Str::isUuid($param), fn ($q) => $q->orWhere('id', $param))->first();
        if ($site === null) {
            throw Rejection::notFound('Site', $param);
        }
        $request->attributes->set('site', $site);

        return $site;
    }
}
