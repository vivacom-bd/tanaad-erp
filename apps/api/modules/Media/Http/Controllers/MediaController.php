<?php

declare(strict_types=1);

namespace Tanaad\Media\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\Identity\Role;
use Tanaad\Media\Models\Media;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Http\ProblemResponse;

/**
 * Content-addressed uploads (SHA-256). Face and ID images (hr_* purposes) live on the
 * separate `hr` disk and are limited to site managers and group roles.
 */
final class MediaController extends ApiController
{
    private const HR_PURPOSES = ['hr_id', 'hr_face'];

    private const HR_ROLES = [Role::SiteManager, Role::GroupAdmin, Role::Ceo];

    private const TTL_MINUTES = 5;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(['photo', 'document', 'hr_id', 'hr_face'])],
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:8192'],
        ]);
        $hr = in_array($data['purpose'], self::HR_PURPOSES, true);
        if ($hr && ! $this->mayAccessHr($request)) {
            return ProblemResponse::make(403, 'forbidden', 'Your role may not upload HR files.');
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $disk = $hr ? 'hr' : 'media';

        $existing = Media::query()->where('bucket', $disk)->where('hash', $hash)->first();
        $status = 200;
        if ($existing === null) {
            $existing = new Media([
                'hash' => $hash, 'bucket' => $disk, 'mime' => (string) $file->getMimeType(), 'size' => $file->getSize(),
                'uploaded_by' => $request->user()?->getAuthIdentifier(),
                'site_id' => $request->user() instanceof User ? $this->siteOf($request->user()) : null,
            ]);
            Storage::disk($disk)->put($existing->path(), (string) file_get_contents($file->getRealPath()));
            $existing->save();
            $status = 201;
        }

        return new JsonResponse([
            'hash' => $existing->hash, 'mime' => $existing->mime, 'size' => $existing->size, 'purpose' => $data['purpose'],
        ], $status);
    }

    public function show(Request $request, string $hash): JsonResponse|Response
    {
        $rows = Media::query()->where('hash', $hash)->get()->keyBy('bucket');
        $row = $rows->get('media');
        if ($row === null && $rows->has('hr')) {
            if (! $this->mayAccessHr($request)) {
                return ProblemResponse::make(403, 'forbidden', 'Your role may not read HR files.');
            }
            $row = $rows->get('hr');
        }
        if (! $row instanceof Media) {
            return ProblemResponse::make(404, 'not_found', 'No such media.');
        }

        $disk = Storage::disk($row->bucket);
        try {
            $expires = now()->addMinutes(self::TTL_MINUTES);
            $url = $disk->temporaryUrl($row->path(), $expires);

            return new JsonResponse(['url' => $url, 'expires_at' => $expires->toIso8601String()]);
        } catch (\RuntimeException) {
            return $disk->response($row->path(), null, ['Content-Type' => $row->mime]);
        }
    }

    private function mayAccessHr(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }
        $allowed = array_map(fn (Role $r): string => $r->value, self::HR_ROLES);

        return $user->siteRoles->contains(fn (UserSiteRole $r): bool => in_array($r->role, $allowed, true));
    }

    private function siteOf(User $user): ?string
    {
        $site = $user->siteRoles->pluck('site_id')->filter()->first();

        return $site === null ? null : (string) $site;
    }
}
