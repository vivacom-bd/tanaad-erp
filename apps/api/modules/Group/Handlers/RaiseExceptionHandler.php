<?php

declare(strict_types=1);

namespace Tanaad\Group\Handlers;

use Illuminate\Support\Str;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Group\Models\BusinessException;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Payload {exception_id?, kind, severity, title, detail, subject_ref?, owner_role?, facts?}.
 * Idempotent per subject: while an open exception of the same kind exists for the same
 * subject_ref at the site, raising again produces no event.
 */
final class RaiseExceptionHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof RaiseException) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $p = $command->payload;
        $errors = [];
        $siteId = $ctx->siteId;
        if ($siteId === null) {
            $errors['site_id'] = 'required';
        }
        $kind = $p['kind'] ?? null;
        if (! in_array($kind, ExceptionRules::KINDS, true)) {
            $errors['kind'] = 'must be one of '.implode(', ', ExceptionRules::KINDS);
        }
        $severity = $p['severity'] ?? null;
        if (! in_array($severity, ExceptionRules::SEVERITIES, true)) {
            $errors['severity'] = 'must be one of '.implode(', ', ExceptionRules::SEVERITIES);
        }
        $title = self::text($p['title'] ?? null, 255);
        if ($title === null) {
            $errors['title'] = 'required, at most 255 characters';
        }
        $detail = self::text($p['detail'] ?? null, 2000);
        if ($detail === null) {
            $errors['detail'] = 'required, at most 2000 characters';
        }
        $subject = $p['subject_ref'] ?? null;
        if ($subject !== null && self::text($subject, 120) === null) {
            $errors['subject_ref'] = 'must be a string of at most 120 characters';
        }
        $owner = $p['owner_role'] ?? Role::SiteManager->value;
        if (! is_string($owner) || Role::tryFrom($owner) === null) {
            $errors['owner_role'] = 'must be a role';
        }
        $facts = $p['facts'] ?? [];
        if (! is_array($facts)) {
            $errors['facts'] = 'must be an object';
        }
        $id = $p['exception_id'] ?? (string) Str::uuid7();
        if (! is_string($id) || ! Str::isUuid($id)) {
            $errors['exception_id'] = 'must be a uuid';
        }
        if ($errors !== []) {
            throw Rejection::invalidPayload($errors);
        }
        /** @var string $siteId */
        /** @var string $kind */
        if (is_string($subject) && BusinessException::openFor($siteId, $kind, $subject) !== null) {
            return [];
        }
        if (BusinessException::query()->whereKey($id)->exists()) {
            throw Rejection::duplicateFact("Exception {$id} already exists.");
        }

        return [Event::from($ctx, 'group.exception_raised', [
            'exception_id' => $id, 'kind' => $kind, 'severity' => $severity, 'title' => $title, 'detail' => $detail,
            'subject_ref' => $subject, 'owner_role' => $owner, 'facts' => (object) $facts,
        ])];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }
}
