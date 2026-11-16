<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Tanaad\Messaging\Commands\QueueMessage;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\StoredEvent;

/**
 * Turns one business fact into one QueueMessage per reachable recipient. Recipients come from
 * message_recipients (phone, not opted out) joined to active users by role or by explicit user id.
 * Template names and parameter order are configuration (tanaad.messaging.templates, keyed by event type).
 */
final class MessageBuilder
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Config $config) {}

    /**
     * @param  list<string>  $roles  audience roles; group roles match group grants, others match the site's grants
     * @param  list<string>  $userIds  explicit recipients (used as given, still need a phone and no opt-out)
     * @return list<QueueMessage>
     */
    public function build(StoredEvent $source, string $title, string $detail, array $roles = [], array $userIds = []): array
    {
        $title = Redactor::clean($title);
        $detail = Redactor::clean($detail);
        $body = mb_substr("Tanaad: {$title}".($detail === '' ? '' : "\n{$detail}"), 0, 900);
        /** @var array<string, array{name?: string, language?: string, params?: list<string>}> $templates */
        $templates = (array) $this->config->get('tanaad.messaging.templates', []);
        $template = $templates[$source->type()] ?? null;
        $fields = ['title' => $title, 'detail' => $detail, 'body' => $body];
        $params = array_map(fn (string $f): string => Redactor::param($fields[$f] ?? ''), $template['params'] ?? []);

        $commands = [];
        foreach ($this->recipients($source->siteId(), $roles, $userIds) as $r) {
            $commands[] = new QueueMessage(new CommandContext($source->siteId(), null, null, $source->event->occurredAt), [
                'message_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "tanaad-message:{$source->id()}:{$r->user_id}")->toString(),
                'user_id' => $r->user_id, 'to' => $r->phone, 'source_event_id' => $source->id(), 'source_type' => $source->type(),
                'template' => $template['name'] ?? null, 'language' => $template['language'] ?? 'en', 'params' => $params, 'body' => $body,
            ]);
        }

        return $commands;
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $userIds
     * @return list<object{user_id: string, phone: string}>
     */
    private function recipients(?string $siteId, array $roles, array $userIds): array
    {
        $q = $this->db->table('message_recipients as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('u.active', true)->where('r.opted_out', false)->where('r.phone', '!=', '')
            ->select('r.user_id', 'r.phone')->orderBy('r.user_id');
        $groupRoles = array_values(array_intersect($roles, ['ceo', 'group_admin']));
        $siteRoles = array_values(array_diff($roles, $groupRoles));
        $q->where(function ($w) use ($siteId, $groupRoles, $siteRoles, $userIds): void {
            if ($userIds !== []) {
                $w->orWhereIn('r.user_id', $userIds);
            }
            $grants = fn ($sub) => $sub->selectRaw('1')->from('user_site_roles as g')->whereColumn('g.user_id', 'u.id')->whereNull('g.deleted_at');
            if ($groupRoles !== []) {
                $w->orWhereExists(fn ($s) => $grants($s)->whereNull('g.site_id')->whereIn('g.role', $groupRoles));
            }
            if ($siteRoles !== [] && $siteId !== null) {
                $w->orWhereExists(fn ($s) => $grants($s)->where('g.site_id', $siteId)->whereIn('g.role', $siteRoles));
            }
        });

        /** @var list<object{user_id: string, phone: string}> */
        return $q->get()->all();
    }
}
