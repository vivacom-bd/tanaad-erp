<?php

declare(strict_types=1);

namespace Tanaad\Sync\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Shared\Ledger\Rejection;

/**
 * POST /api/v1/sites/{site}/events — offline outbox ingestion (§7.2). Each envelope is
 * its own transaction; a rejection is reported and the batch continues.
 */
final class IngestController extends ApiController
{
    public function __construct(
        private readonly Registry $registry,
        private readonly CommandBus $bus,
        private readonly EventStore $store,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $batchId = $request->input('batch_id');
        $events = $request->input('events');
        $max = (int) $this->config->get('tanaad.ingest_batch_max', 500);

        $errors = [];
        if (! is_string($batchId) || ! Str::isUuid($batchId)) {
            $errors['batch_id'] = 'must be a UUID';
        }
        if (! is_array($events) || ! array_is_list($events)) {
            $errors['events'] = 'must be a list';
        } elseif (count($events) > $max) {
            $errors['events'] = "at most {$max} events per batch";
        }
        if ($errors !== []) {
            throw Rejection::invalidPayload($errors);
        }
        /** @var string $batchId */
        /** @var list<mixed> $events */
        $base = $this->context($request, $site->id);

        $results = [];
        foreach ($events as $raw) {
            $results[] = $this->ingest(is_array($raw) ? $raw : [], $site->id, $base, $batchId);
        }

        return new JsonResponse(['results' => $results, 'snapshot_seq' => $this->store->lastSeq()]);
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>
     */
    private function ingest(array $raw, string $siteId, CommandContext $base, string $batchId): array
    {
        $id = is_string($raw['id'] ?? null) ? $raw['id'] : null;
        try {
            /** @var array<string, mixed> $raw */
            $envelope = EventEnvelope::fromArray($raw);
            $envSite = $raw['site_id'] ?? null;
            if ($envSite !== null && $envSite !== $siteId) {
                throw Rejection::forbidden('The event belongs to another site.');
            }
            if ($envelope->occurredAt->greaterThan(CarbonImmutable::now()->addHours(24))) {
                throw Rejection::invalidPayload(['occurred_at' => 'more than 24 hours in the future']);
            }
            $context = new CommandContext(
                siteId: $siteId,
                actorId: $base->actorId,
                deviceId: $base->deviceId ?? $envelope->deviceId,
                occurredAt: $envelope->occurredAt,
                eventId: $envelope->id,
                provisionalRef: $envelope->provisionalRef,
            );
            $command = $this->registry->factoryFor($envelope->type)->fromEnvelope($envelope, $context);
            $result = $this->bus->dispatch($command, $batchId);
            $seq = $result->primary()?->seq;
            if ($result->isDuplicate()) {
                return ['id' => $envelope->id, 'status' => 'duplicate', 'seq' => $seq, 'assigned' => (object) $result->assigned];
            }

            return ['id' => $envelope->id, 'status' => 'accepted', 'seq' => $seq, 'assigned' => (object) $result->assigned];
        } catch (Rejection $r) {
            $out = ['id' => $id, 'status' => 'rejected', 'code' => $r->rejectionCode->value, 'reason' => $r->getMessage()];
            if ($r->details !== []) {
                $out['details'] = $r->details;
            }

            return $out;
        }
    }
}
