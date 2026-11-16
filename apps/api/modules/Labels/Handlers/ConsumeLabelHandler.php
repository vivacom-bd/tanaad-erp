<?php

declare(strict_types=1);

namespace Tanaad\Labels\Handlers;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Labels\Commands\ConsumeLabel;
use Tanaad\Labels\Models\Label;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Consumes an in-stock label of the command's site, by id or by code. */
final class ConsumeLabelHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ConsumeLabel) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $id = $p->optUuid('label_id');
        $code = $p->optStr('code', 64);
        if ($id === null && $code === null) {
            $p->error('label_id', 'label_id or code is required');
        }
        $stage = $p->optStr('consumed_by_stage', 32);
        $ref = $p->optStr('consumed_ref', 120);
        $p->check();
        $tz = (string) (DB::table('sites')->where('id', $siteId)->value('timezone') ?? throw Rejection::notFound('Site', $siteId));

        $label = Label::query()->where('site_id', $siteId)
            ->when($id !== null, fn ($q) => $q->where('id', $id), fn ($q) => $q->where('code', $code))
            ->lockForUpdate()->first() ?? throw Rejection::notFound('Label', $id ?? (string) $code);
        if ($label->status !== 'in_stock') {
            throw Rejection::stateConflict("Label {$label->code} is {$label->status}, not in stock.");
        }
        // A label scanned onto an open dispatch (dispatch_bundles, read by table name) cannot be consumed.
        $loadedOn = DB::table('dispatch_bundles')->join('dispatches', 'dispatches.id', '=', 'dispatch_bundles.dispatch_id')
            ->where('dispatch_bundles.label_id', $label->id)->whereIn('dispatches.gate_status', ['pending', 'hold'])
            ->value('dispatches.order_ref');
        if ($loadedOn !== null) {
            throw Rejection::stateConflict("Label {$label->code} is loaded on dispatch {$loadedOn}; unscan it first.");
        }

        return [Event::from($ctx, 'labels.consumed', [
            'label_id' => $label->id,
            'code' => $label->code,
            'consumed_by_stage' => $stage,
            'consumed_ref' => $ref,
            'day' => $ctx->occurredAt->setTimezone($tz)->toDateString(),
        ])];
    }
}
