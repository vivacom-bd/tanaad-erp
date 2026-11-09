<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Tanaad\Config\Commands\ChangeRefList;
use Tanaad\Config\Models\RefItem;
use Tanaad\Config\Support\Payload;
use Tanaad\Config\Support\RefLists;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Upserts one master-data option. System items keep their system flag and stay active. */
final class ChangeRefListHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ChangeRefList) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctxSite = $command->context()->siteId;
        $p = new Payload($command->payload);
        $itemId = $p->uuid('item_id');
        $list = $p->oneOf('list', RefLists::ALL);
        $label = $p->str('label', 120);
        $siteId = $p->has('site_id') ? $p->optUuid('site_id') : $ctxSite;
        if ($siteId !== null && $siteId !== $ctxSite) {
            $p->error('site_id', 'must be this site, or null for the whole group');
        }
        $payload = [
            'item_id' => $itemId,
            'list' => $list,
            'site_id' => $siteId,
            'label' => $label,
            'value' => $p->optNum('value', null),
            'unit' => $p->optStr('unit', 32),
            'color' => $p->optStr('color', 64),
            'parent_id' => $p->optStr('parent_id', 64),
            'active' => $p->bool('active', true),
            'sort_order' => $p->int('sort_order', 0, 0),
            'system' => $p->bool('system', false),
            'example' => $p->bool('example', false),
            'code' => $p->optStr('code', 64),
        ];
        $p->check();

        $existing = RefItem::query()->find($itemId);
        if ($existing !== null) {
            if ($existing->site_id !== $siteId) {
                throw Rejection::stateConflict('An item cannot move between sites.');
            }
            if ($existing->list !== $list) {
                throw Rejection::stateConflict('An item cannot move between lists.');
            }
            if ($existing->system && ! $payload['active']) {
                throw Rejection::stateConflict('A system item can be renamed or reordered but not deactivated.');
            }
            $payload['system'] = $existing->system;
            $payload['code'] = $existing->code;
        } elseif ($payload['code'] !== null && RefItem::query()->where('code', $payload['code'])->exists()) {
            throw Rejection::duplicateFact("Code {$payload['code']} is already used.");
        }

        return [Event::from($command->context(), 'config.ref_list_changed', $payload)];
    }
}
