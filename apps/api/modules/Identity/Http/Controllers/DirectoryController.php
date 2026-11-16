<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Rejection;

/**
 * GET /api/v1/directory — names for the user ids rows carry (recorded_by, decided_by, ...).
 * The users sharing a site with the caller (group roles: every user), each with only the roles they
 * hold at the caller's sites plus group roles. No phones, e-mails, usernames, PINs or person links.
 * Private cache (per user) with an ETag, so a client can revalidate cheaply.
 */
final class DirectoryController extends ApiController
{
    public function __construct(private readonly Access $access) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        $caller = $request->user();
        if (! $caller instanceof User) {
            throw Rejection::forbidden('Sign in first.');
        }
        $caller->loadMissing('siteRoles');
        $sites = $this->access->siteIds($caller);   // null = every site

        $users = User::query()->with('siteRoles.site')->orderBy('name')
            // Group-role users (CEO, admins) decide and approve at every site, so everyone sees their names.
            ->when($sites !== null, fn ($q) => $q->whereHas('siteRoles', fn ($r) => $r->whereIn('site_id', $sites)->orWhereNull('site_id')))
            ->get(['id', 'name', 'code', 'active']);

        $data = [];
        foreach ($users as $u) {
            $roles = $u->siteRoles->filter(fn (UserSiteRole $r): bool => $r->site_id === null || $sites === null || in_array($r->site_id, $sites, true))
                ->map(fn (UserSiteRole $r): array => ['role' => $r->role, 'siteId' => $r->site_id, 'siteCode' => $r->site?->code])
                ->sortBy(fn (array $r): string => ($r['siteCode'] ?? '').'|'.$r['role'])->values()->all();
            $data[] = ['id' => $u->id, 'name' => $u->name, 'code' => $u->code, 'active' => $u->active, 'roles' => $roles];
        }

        $etag = '"'.hash('xxh128', (string) json_encode($data)).'"';
        $headers = ['Cache-Control' => 'private, max-age=300', 'ETag' => $etag, 'Vary' => 'Authorization'];
        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response('', 304, $headers);
        }

        return new JsonResponse(['data' => $data], 200, $headers);
    }
}
