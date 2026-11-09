<?php

declare(strict_types=1);

namespace Tanaad\Group\Handlers;

use Illuminate\Support\Str;
use Tanaad\Group\Commands\SendBrief;
use Tanaad\Group\Support\BriefRules;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Payload {brief_id?, day (Y-m-d), channel, recipient_ids?: [uuid], lines: [string]}.
 * Records that a brief was composed and handed over (copied, sent, or logged); delivery itself is outside the ledger.
 */
final class SendBriefHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof SendBrief) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $p = $command->payload;
        $errors = [];
        $day = $p['day'] ?? null;
        if (! is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            $errors['day'] = 'must be a date, YYYY-MM-DD';
        }
        $channel = $p['channel'] ?? null;
        if (! in_array($channel, BriefRules::CHANNELS, true)) {
            $errors['channel'] = 'must be one of '.implode(', ', BriefRules::CHANNELS);
        }
        $lines = $p['lines'] ?? null;
        if (is_array($lines)) {
            // The HTTP stack turns blank lines into null; a blank spacer line is meaningful here.
            $lines = array_map(fn ($l) => $l ?? '', $lines);
        }
        if (! is_array($lines) || ! array_is_list($lines) || $lines === [] || count($lines) > BriefRules::MAX_LINES) {
            $errors['lines'] = 'must be a list of 1 to '.BriefRules::MAX_LINES.' strings';
        } else {
            foreach ($lines as $line) {
                if (! is_string($line) || mb_strlen($line) > BriefRules::MAX_LINE) {
                    $errors['lines'] = 'every line must be a string of at most '.BriefRules::MAX_LINE.' characters';
                    break;
                }
            }
        }
        $recipients = $p['recipient_ids'] ?? [];
        if (! is_array($recipients) || ! array_is_list($recipients) || count($recipients) > 50
            || array_filter($recipients, fn ($r): bool => ! is_string($r) || ! Str::isUuid($r)) !== []) {
            $errors['recipient_ids'] = 'must be a list of user ids';
        }
        $id = $p['brief_id'] ?? $ctx->eventId ?? (string) Str::uuid7();
        if (! is_string($id) || ! Str::isUuid($id)) {
            $errors['brief_id'] = 'must be a uuid';
        }
        if ($errors !== []) {
            throw Rejection::invalidPayload($errors);
        }

        return [Event::from($ctx, 'group.brief_sent', [
            'brief_id' => $id, 'day' => $day, 'channel' => $channel,
            'recipient_ids' => array_values(array_unique($recipients)), 'lines' => $lines,
        ])];
    }
}
