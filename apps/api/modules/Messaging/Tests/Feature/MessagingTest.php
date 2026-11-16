<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Messaging\Jobs\DeliverMessage;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\EventStore;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Shared\Ledger\Replay;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $this->user = fn (string $code): User => User::query()->where('code', $code)->firstOrFail();
    $token = fn (string $code): string => ($this->user)($code)->createToken('office', ['user'])->plainTextToken;
    $this->admin = $token('u_admin');
    $this->ceo = $token('u_ceo1');
    $this->manager = $token('u_sm_mats');
    $this->otherManager = $token('u_sm_bags');
    $this->cashier = $token('u_cash_mats');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $this->bags = (string) Site::query()->where('code', 'bags')->value('id');
    $this->phones = ['u_ceo1' => '+256700000001', 'u_sm_mats' => '+256700000002', 'u_sm_bags' => '+256700000003'];
});

function saveRecipients(Tests\TestCase $t, array $codes = ['u_ceo1', 'u_sm_mats', 'u_sm_bags']): void
{
    foreach ($codes as $code) {
        $t->withToken($t->admin)->postJson('/api/v1/messages/recipients', ['userId' => ($t->user)($code)->id, 'phone' => $t->phones[$code]])->assertCreated();
    }
}

function foreign(string $type, ?string $siteId, array $payload): void
{
    $stored = app(EventStore::class)->append([new Event(
        id: (string) Str::uuid7(), type: $type, siteId: $siteId, actorId: null, deviceId: null,
        occurredAt: CarbonImmutable::now(), payload: $payload,
    )])[0];
    $bus = app(CommandBus::class);
    $bus->project($stored);
    foreach (app(Registry::class)->policiesFor($type) as $policy) {
        foreach ($policy->react($stored) as $command) {
            $bus->dispatch($command);
        }
    }
    foreach (app(Registry::class)->reactorsFor($type) as $reactor) {
        $reactor->react($stored);
    }
}

function exceptionPayload(string $severity, string $kind = 'mass_gap'): array
{
    return ['exception_id' => (string) Str::uuid7(), 'kind' => $kind, 'severity' => $severity, 'title' => 'Mass gap on line 2', 'detail' => 'Output is 9% below input.', 'owner_role' => 'site_manager', 'subject_ref' => 'x', 'facts' => []];
}

function messageRows(): array
{
    return DB::table('outbound_messages')->orderBy('id')->get()->map(function ($r) {
        $a = (array) $r;
        unset($a['created_at'], $a['updated_at']);

        return $a;
    })->all();
}

it('saves recipients for the admin only, validates the number, and replays', function (): void {
    $id = ($this->user)('u_ceo1')->id;
    $this->withToken($this->ceo)->postJson('/api/v1/messages/recipients', ['userId' => $id, 'phone' => '+256700000001'])->assertForbidden();
    $this->withToken($this->admin)->postJson('/api/v1/messages/recipients', ['userId' => $id, 'phone' => 'abc'])->assertStatus(422);
    $this->withToken($this->admin)->postJson('/api/v1/messages/recipients', ['userId' => (string) Str::uuid7(), 'phone' => '+256700000001'])->assertStatus(422);
    $this->withToken($this->admin)->postJson('/api/v1/messages/recipients', ['userId' => $id, 'phone' => '0700 000 001'])->assertStatus(422);
    $this->withToken($this->admin)->postJson('/api/v1/messages/recipients', ['userId' => $id, 'phone' => '+256 700-000001', 'optedOut' => false])->assertCreated()->assertJsonPath('recipient.phone', '+256700000001');
    $this->withToken($this->admin)->postJson('/api/v1/messages/recipients', ['userId' => $id, 'phone' => '+256700000009', 'optedOut' => true])->assertCreated();
    $before = DB::table('message_recipients')->get()->map(fn ($r) => [$r->user_id, $r->phone, (bool) $r->opted_out])->all();
    expect($before)->toBe([[$id, '+256700000009', true]]);
    app(Replay::class)->rebuild();
    expect(DB::table('message_recipients')->get()->map(fn ($r) => [$r->user_id, $r->phone, (bool) $r->opted_out])->all())->toBe($before);
});

it('sends a critical exception to the CEO and the site manager through the log driver', function (): void {
    saveRecipients($this);
    Log::spy();
    foreign('group.exception_raised', $this->mats, exceptionPayload('critical'));

    $rows = messageRows();
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'status'))->each->toBe('logged')
        ->and(array_column($rows, 'attempts'))->each->toBe(1)
        ->and(collect($rows)->pluck('to_phone')->sort()->values()->all())->toBe(['+256700000001', '+256700000002'])
        ->and($rows[0]['body'])->toContain('Critical: Mass gap on line 2')->and($rows[0]['site_id'])->toBe($this->mats);
    Log::shouldHaveReceived('info')->with('Outbound message', Mockery::type('array'))->twice();
    expect(DB::table('events')->whereIn('type', ['messaging.queued', 'messaging.delivered'])->count())->toBe(4);

    $before = messageRows();
    app(Replay::class)->rebuild();
    expect(messageRows())->toEqual($before);
});

it('stays quiet for warnings, repeats, opted-out users and gate_variance exceptions', function (): void {
    saveRecipients($this);
    foreign('group.exception_raised', $this->mats, exceptionPayload('warning'));
    foreign('group.exception_raised', $this->mats, exceptionPayload('critical', 'gate_variance'));
    expect(messageRows())->toBe([]);

    $this->withToken($this->admin)->postJson('/api/v1/messages/recipients', ['userId' => ($this->user)('u_ceo1')->id, 'phone' => '+256700000001', 'optedOut' => true])->assertCreated();
    $payload = exceptionPayload('critical');
    foreign('group.exception_raised', $this->bags, $payload);
    expect(messageRows())->toHaveCount(1);   // only the Bags manager; the CEO opted out, the Mats manager is at another site
});

it('alerts the CEO when a gate is held', function (): void {
    saveRecipients($this);
    foreign('dispatch.gate_held', $this->mats, ['dispatch_id' => (string) Str::uuid7(), 'order_ref' => 'SO-2301', 'reasons' => ['Weight 6% under'], 'site_name' => 'Mats', 'expected_kg' => 100, 'kg' => 90]);
    $rows = messageRows();
    expect($rows)->toHaveCount(1)->and($rows[0]['to_phone'])->toBe('+256700000001')
        ->and($rows[0]['body'])->toContain('Gate locked at Mats: order SO-2301')->toContain('Weight 6% under');
});

it('sends a brief to its listed recipients, not for the copy channel, and never with a PIN', function (): void {
    saveRecipients($this);
    $ids = [($this->user)('u_sm_mats')->id];
    $body = ['day' => '2026-11-09', 'channel' => 'copy', 'recipientIds' => $ids, 'lines' => ['*Tanaad*', 'PIN 4821 for the gate']];
    $this->withToken($this->ceo)->postJson('/api/v1/briefs', $body)->assertCreated();
    expect(messageRows())->toBe([]);
    $this->withToken($this->ceo)->postJson('/api/v1/briefs', [...$body, 'channel' => 'whatsapp'])->assertCreated();
    $rows = messageRows();
    expect($rows)->toHaveCount(1)->and($rows[0]['to_phone'])->toBe('+256700000002')
        ->and($rows[0]['body'])->toContain('Morning brief 2026-11-09')->not->toContain('4821')->toContain('[hidden]');
});

it('delivers through WhatsApp with the mapped template, and records provider ids', function (): void {
    config(['tanaad.messaging.driver' => 'whatsapp_cloud', 'tanaad.messaging.whatsapp.token' => 'tok_secret', 'tanaad.messaging.whatsapp.phone_id' => '1555']);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
    saveRecipients($this, ['u_ceo1']);
    foreign('group.exception_raised', $this->mats, exceptionPayload('critical'));

    Http::assertSent(function ($request): bool {
        $t = $request->data()['template'];

        return $request->url() === 'https://graph.facebook.com/v21.0/1555/messages' && $request->hasHeader('Authorization', 'Bearer tok_secret')
            && $request->data()['to'] === '256700000001' && $t['name'] === 'tanaad_critical_alert'
            && $t['components'][0]['parameters'][0]['text'] === 'Critical: Mass gap on line 2 (Mats Factory)';
    });
    $row = messageRows()[0];
    expect($row['status'])->toBe('delivered')->and($row['provider_id'])->toBe('wamid.1')->and($row['driver'])->toBe('whatsapp_cloud');
});

it('records failures, retries with backoff, is idempotent, and ends failed after the last attempt', function (): void {
    config(['tanaad.messaging.driver' => 'whatsapp_cloud', 'tanaad.messaging.whatsapp.token' => 'tok_secret', 'tanaad.messaging.whatsapp.phone_id' => '1555', 'tanaad.messaging.max_attempts' => 3]);
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['message' => 'Rate limit']], 429)->push(['error' => ['message' => 'Down']], 500)->push(['messages' => [['id' => 'wamid.9']]], 200)]);
    saveRecipients($this, ['u_ceo1']);
    foreign('group.exception_raised', $this->mats, exceptionPayload('critical'));

    $id = (string) DB::table('outbound_messages')->value('id');
    $row = fn () => DB::table('outbound_messages')->where('id', $id)->first();
    expect($row()->status)->toBe('retrying')->and($row()->attempts)->toBe(1)->and($row()->last_error)->toContain('HTTP 429')->not->toContain('tok_secret');

    (new DeliverMessage($id))->handle(app(CommandBus::class), app(Tanaad\Messaging\Contracts\Messenger::class), app(Illuminate\Database\ConnectionInterface::class), app(Illuminate\Contracts\Config\Repository::class));
    expect($row()->status)->toBe('retrying')->and($row()->attempts)->toBe(2);
    dispatch_sync(new DeliverMessage($id));
    expect($row()->status)->toBe('delivered')->and($row()->attempts)->toBe(3);

    // A settled message is never sent again, and a stale failure records nothing.
    dispatch_sync(new DeliverMessage($id));
    Http::assertSentCount(3);
    expect(DB::table('events')->where('type', 'messaging.delivered')->count())->toBe(1)
        ->and(DB::table('events')->where('type', 'messaging.failed')->count())->toBe(2);

    $before = messageRows();
    app(Replay::class)->rebuild();
    expect(messageRows())->toEqual($before);
});

it('marks a message failed once attempts run out', function (): void {
    config(['tanaad.messaging.driver' => 'whatsapp_cloud', 'tanaad.messaging.whatsapp.token' => 'tok', 'tanaad.messaging.whatsapp.phone_id' => '1', 'tanaad.messaging.max_attempts' => 2]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'No']], 500)]);
    saveRecipients($this, ['u_ceo1']);
    foreign('group.exception_raised', $this->mats, exceptionPayload('critical'));
    $id = (string) DB::table('outbound_messages')->value('id');
    dispatch_sync(new DeliverMessage($id));
    $row = DB::table('outbound_messages')->where('id', $id)->first();
    expect($row->status)->toBe('failed')->and($row->attempts)->toBe(2)->and($row->settled_at)->not->toBeNull();
    dispatch_sync(new DeliverMessage($id));
    Http::assertSentCount(2);
});

it('fails clearly, without calling out, when WhatsApp is not configured', function (): void {
    config(['tanaad.messaging.driver' => 'whatsapp_cloud', 'tanaad.messaging.whatsapp.token' => null]);
    Http::fake();
    saveRecipients($this, ['u_ceo1']);
    foreign('group.exception_raised', $this->mats, exceptionPayload('critical'));
    Http::assertNothingSent();
    expect(DB::table('outbound_messages')->value('last_error'))->toContain('not configured');
});

it('lists the log by role and site, masks numbers, and filters by status', function (): void {
    saveRecipients($this);
    foreign('group.exception_raised', $this->mats, exceptionPayload('critical'));
    foreign('group.exception_raised', $this->bags, exceptionPayload('critical'));

    $all = $this->withToken($this->ceo)->getJson('/api/v1/messages')->assertOk()->json('data');
    expect($all)->toHaveCount(4)->and($all[0]['to'])->toContain('*')->not->toContain('700000001')->and($all[0])->toHaveKey('status');
    $this->withToken($this->ceo)->getJson('/api/v1/messages?status=failed')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($this->ceo)->getJson('/api/v1/messages?status=logged&site='.$this->bags)->assertOk()->assertJsonCount(2, 'data');
    $mine = $this->withToken($this->manager)->getJson('/api/v1/messages')->assertOk()->json('data');
    expect($mine)->toHaveCount(2)->and(array_unique(array_column($mine, 'siteId')))->toBe([$this->mats]);
    $this->withToken($this->manager)->getJson('/api/v1/messages?site='.$this->bags)->assertForbidden();
    $this->withToken($this->cashier)->getJson('/api/v1/messages')->assertForbidden();
});

it('refuses users issuing server-only messaging events through sync', function (): void {
    $this->withToken($this->admin)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => [[
        'id' => (string) Str::uuid7(), 'type' => 'messaging.queued', 'occurred_at' => now()->toIso8601String(), 'payload' => [],
    ]]])->assertOk()->assertJsonPath('results.0.code', 'unknown_type');
    expect(DB::table('outbound_messages')->count())->toBe(0);
});

it('tells the CEO and site manager about an order decision, from Orders\' payload', function (): void {
    saveRecipients($this);
    $order = (string) Str::uuid7();
    foreign('orders.held', $this->mats, ['order_id' => $order, 'number' => 'SO-MAT-0007', 'client_name' => 'Kampala Traders', 'status' => 'held', 'reason' => 'Balance overdue', 'over_limit' => true]);
    foreign('orders.approved', $this->mats, ['order_id' => $order, 'number' => 'SO-MAT-0007', 'client_name' => 'Kampala Traders', 'status' => 'awaiting_cash', 'reason' => null, 'over_limit' => true]);
    $rows = messageRows();
    expect($rows)->toHaveCount(4)
        ->and(collect($rows)->pluck('to_phone')->unique()->sort()->values()->all())->toBe(['+256700000001', '+256700000002'])
        ->and(collect($rows)->pluck('body')->filter(fn ($b) => str_contains($b, 'Order SO-MAT-0007 held for Kampala Traders') && str_contains($b, 'Reason: Balance overdue') && str_contains($b, 'credit limit'))->count())->toBe(2)
        ->and(collect($rows)->pluck('body')->filter(fn ($b) => str_contains($b, 'Order SO-MAT-0007 approved, awaiting cash for Kampala Traders'))->count())->toBe(2);
});

it('never resends a provider-accepted message when recording the delivery fails', function (): void {
    expect((new DeliverMessage('x'))->tries())->toBe((int) config('tanaad.messaging.max_attempts'));
    config(['tanaad.messaging.driver' => 'whatsapp_cloud', 'tanaad.messaging.whatsapp.token' => 'tok', 'tanaad.messaging.whatsapp.phone_id' => '1']);
    // The first send is accepted; a second one would fail loudly.
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['messages' => [['id' => 'wamid.7']]], 200)->push(['error' => ['message' => 'Resent']], 500)]);
    // After the provider accepts, the read row vanishes, so RecordDelivery fails (as a DB blip would).
    $inner = app(Tanaad\Messaging\Contracts\Messenger::class);
    app()->instance(Tanaad\Messaging\Contracts\Messenger::class, new class($inner) implements Tanaad\Messaging\Contracts\Messenger
    {
        public bool $break = true;

        public function __construct(private readonly Tanaad\Messaging\Contracts\Messenger $inner) {}

        public function send(Tanaad\Messaging\Support\OutboundMessage $message): Tanaad\Messaging\Support\DeliveryResult
        {
            $result = $this->inner->send($message);
            if ($this->break) {
                DB::table('outbound_messages')->where('id', $message->id)->delete();
            }

            return $result;
        }
    });
    saveRecipients($this, ['u_ceo1']);
    try {
        foreign('group.exception_raised', $this->mats, exceptionPayload('critical'));
    } catch (Throwable) {
        // the failed recording surfaces from the synchronous job
    }
    expect(DB::table('message_send_receipts')->count())->toBe(1)->and(DB::table('events')->where('type', 'messaging.delivered')->count())->toBe(0);

    app(Replay::class)->rebuild(); // the read row is back, still not settled
    $id = (string) DB::table('outbound_messages')->value('id');
    dispatch_sync(new DeliverMessage($id));
    Http::assertSentCount(1);
    $row = DB::table('outbound_messages')->where('id', $id)->first();
    expect($row->status)->toBe('delivered')->and($row->provider_id)->toBe('wamid.7')
        ->and(DB::table('events')->where('type', 'messaging.delivered')->count())->toBe(1);
});
