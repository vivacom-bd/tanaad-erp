<?php

declare(strict_types=1);

namespace Tanaad\Orders\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Orders\Commands\RegisterClient;
use Tanaad\Orders\Commands\UpdateClient;
use Tanaad\Orders\Support\Sales;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/**
 * Registers or changes a client (group-wide). The market must be an active entry of the `market`
 * master-data list when that list has entries; the credit limit and days are the client's terms
 * (a local client normally has none: cash before delivery). Nothing here is a fixed vocabulary
 * except the two channels. The credit limit is in the client's currency_pref (orders are priced in it).
 * Who may set the terms is the authorizer's rule (CEO or group admin only).
 */
final class SaveClientHandler implements Handler
{
    public function handle(Command $command): array
    {
        $update = $command instanceof UpdateClient;
        if (! $update && ! $command instanceof RegisterClient) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        /** @var RegisterClient|UpdateClient $command */
        $ctx = $command->context();
        $p = new Payload($command->payload);
        $id = $update ? $p->uuid('client_id') : ($p->optUuid('client_id') ?? (string) Str::uuid7());
        $name = $p->str('name', 160);
        $country = $p->str('country', 80);
        // On an update, an omitted term keeps the client's current value (the web sends a term only when it changed).
        $current = $update && $id !== '' ? DB::table('clients')->where('id', $id)->first(['channel', 'currency_pref', 'credit_limit', 'credit_days']) : null;
        $channel = $current !== null && ! $p->has('channel') ? (string) $current->channel : $p->oneOf('channel', Sales::CHANNELS);
        $currency = $current !== null && ! $p->has('currency_pref') ? (string) $current->currency_pref : $p->currency('currency_pref');
        $limit = $current !== null && ! $p->has('credit_limit') ? (float) $current->credit_limit : ($p->optNum('credit_limit') ?? 0.0);
        if ($limit > Sales::MAX_MONEY) {
            $p->error('credit_limit', 'is too large');
        }
        $days = $current !== null && ! $p->has('credit_days') ? (int) $current->credit_days : ($p->optInt('credit_days') ?? 0);
        if ($days > 3650) {
            $p->error('credit_days', 'must be at most 3650');
        }
        $whatsapp = $p->text('whatsapp', 40);
        $contact = $p->text('contact', 160);
        $active = $p->bool('active', true);
        $p->check();

        $markets = DB::table('ref_items')->where('list', 'market')->whereNull('deleted_at')->where('active', true)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $ctx->siteId))->pluck('label')->all();
        if ($markets !== [] && ! in_array($country, $markets, true)) {
            throw Rejection::invalidPayload(['country' => 'must be a market from master data']);
        }
        $exists = DB::table('clients')->where('id', $id)->lockForUpdate()->exists();
        if ($update && ! $exists) {
            throw Rejection::notFound('Client', $id);
        }
        if (! $update && $exists) {
            throw Rejection::duplicateFact("Client {$id} already exists.");
        }

        return [Event::from($ctx, 'orders.client_saved', [
            'client_id' => $id, 'name' => $name, 'country' => $country, 'channel' => $channel, 'currency_pref' => $currency,
            'credit_limit' => Rounding::money($limit), 'credit_days' => $days, 'whatsapp' => $whatsapp, 'contact' => $contact,
            'active' => $active, 'is_new' => ! $update,
        ])];
    }
}
