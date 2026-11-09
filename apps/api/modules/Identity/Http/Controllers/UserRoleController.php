<?php

declare(strict_types=1);

namespace Tanaad\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;

final class UserRoleController extends ApiController
{
    public function __construct(private readonly CommandBus $bus) {}

    public function store(Request $request, string $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(Role::values())],
            'site' => ['nullable', 'string'],     // site code or id; omitted for group roles
        ]);
        $siteId = null;
        if (isset($data['site'])) {
            $siteId = Site::query()->where('code', $data['site'])
                ->when(\Illuminate\Support\Str::isUuid($data['site']), fn ($q) => $q->orWhere('id', $data['site']))->value('id');
            $siteId = is_string($siteId) ? $siteId : null;
        }

        $result = $this->bus->dispatch(new AssignRole($this->context($request, null), $user, $data['role'], $siteId));

        return $this->created(['user' => User::query()->with('siteRoles.site')->findOrFail($user)->toProfile(), 'event_id' => $result->primary()?->id()]);
    }
}
