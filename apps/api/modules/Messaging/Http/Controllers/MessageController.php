<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Http\Controllers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Identity\Models\User;
use Tanaad\Messaging\Authorization\MessagingAuthorizer;
use Tanaad\Messaging\Commands\SaveRecipient;
use Tanaad\Messaging\Support\Redactor;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Rejection;

final class MessageController extends ApiController
{
    public function __construct(
        private readonly CommandBus $bus,
        private readonly ConnectionInterface $db,
        private readonly MessagingAuthorizer $authorizer,
    ) {}

    /** ?status=queued|retrying|logged|delivered|failed, ?site=<site id>; newest first, at most 100. Numbers are masked. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('Sign in first.');
        }
        $sites = $this->authorizer->readableSites($user->loadMissing('siteRoles'));
        $status = $request->query('status');
        $site = $request->query('site');
        if (is_string($site) && $site !== '' && $sites !== null && ! in_array($site, $sites, true)) {
            throw Rejection::forbidden('That site is not yours.');
        }
        $rows = $this->db->table('outbound_messages')
            ->when($sites !== null, fn ($q) => $q->whereIn('site_id', $sites))
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->when(is_string($site) && $site !== '', fn ($q) => $q->where('site_id', $site))
            ->orderByDesc('seq')->limit(100)->get();

        return new JsonResponse(['data' => $rows->map(fn (object $m): array => [
            'id' => $m->id, 'siteId' => $m->site_id, 'userId' => $m->user_id, 'to' => Redactor::mask($m->to_phone),
            'sourceType' => $m->source_type, 'sourceEventId' => $m->source_event_id, 'template' => $m->template, 'body' => $m->body,
            'status' => $m->status, 'attempts' => (int) $m->attempts, 'driver' => $m->driver, 'lastError' => $m->last_error,
            'queuedAt' => $m->queued_at, 'settledAt' => $m->settled_at,
        ])->values()->all()]);
    }

    public function saveRecipient(Request $request): JsonResponse
    {
        $payload = [];
        foreach (['userId' => 'user_id', 'phone' => 'phone', 'optedOut' => 'opted_out'] as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            }
        }
        $result = $this->bus->dispatch(new SaveRecipient($this->context($request, null), $payload));
        $event = $result->events[0] ?? throw new \LogicException('No event in the result.');
        $p = $event->payload();

        return $this->created(['recipient' => ['userId' => $p['user_id'], 'phone' => $p['phone'], 'optedOut' => $p['opted_out']], 'event_id' => $event->id()]);
    }
}
