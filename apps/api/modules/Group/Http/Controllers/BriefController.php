<?php

declare(strict_types=1);

namespace Tanaad\Group\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Group\Authorization\GroupAuthorizer;
use Tanaad\Group\Commands\SendBrief;
use Tanaad\Group\Models\Brief;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Rejection;

/** The morning brief log. Group-wide; CEO and group admin only. */
final class BriefController extends ApiController
{
    public function __construct(private readonly CommandBus $bus, private readonly GroupAuthorizer $authorizer) {}

    /** ?day=YYYY-MM-DD, newest first, at most 100. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorizer->canReadAll($user->loadMissing('siteRoles'))) {
            throw Rejection::forbidden('Only group roles read the brief log.');
        }
        $day = $request->query('day');

        return new JsonResponse(['data' => Brief::query()
            ->when(is_string($day) && $day !== '', fn ($q) => $q->where('day', $day))
            ->orderByDesc('sent_at')->orderByDesc('seq')->limit(100)->get()
            ->map(fn (Brief $b): array => $b->shape())->values()->all()]);
    }

    public function send(Request $request): JsonResponse
    {
        $payload = [];
        foreach (['briefId' => 'brief_id', 'day' => 'day', 'channel' => 'channel', 'recipientIds' => 'recipient_ids', 'lines' => 'lines'] as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            }
        }
        $result = $this->bus->dispatch(new SendBrief($this->context($request, null), $payload));
        $event = $result->events[0] ?? throw new \LogicException('No event in the result.');
        $brief = Brief::query()->whereKey($event->payload()['brief_id'])->firstOrFail();

        return $this->created(['brief' => $brief->shape(), 'event_id' => $event->id()]);
    }
}
