<?php

declare(strict_types=1);

namespace Tanaad\Group\Handlers;

use Tanaad\Group\Commands\DecideException;
use Tanaad\Group\Models\BusinessException;
use Tanaad\Group\Support\ExceptionRules;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Payload {exception_id, decision: approve|reject|acknowledge|hold, note}. A reject or hold needs a note.
 * A hold keeps the exception active (status held). Only an open or held exception at the command's site can be decided. Who may decide is GroupAuthorizer.
 */
final class DecideExceptionHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof DecideException) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $p = $command->payload;
        $errors = [];
        $id = $p['exception_id'] ?? null;
        if (! is_string($id) || $id === '') {
            $errors['exception_id'] = 'required';
        }
        $decision = $p['decision'] ?? null;
        if (! is_string($decision) || ! isset(ExceptionRules::DECISIONS[$decision])) {
            $errors['decision'] = 'must be one of '.implode(', ', array_keys(ExceptionRules::DECISIONS));
        }
        $note = is_string($p['note'] ?? null) ? trim($p['note']) : null;
        $note = $note === '' ? null : $note;
        if ($note !== null && mb_strlen($note) > 1000) {
            $errors['note'] = 'at most 1000 characters';
        }
        if (in_array($decision, ['reject', 'hold'], true) && $note === null) {
            $errors['note'] = "required when {$decision}ing";
        }
        if ($errors !== []) {
            throw Rejection::invalidPayload($errors);
        }
        /** @var string $id */
        /** @var string $decision */
        $exception = BusinessException::query()->where(['id' => $id, 'site_id' => $ctx->siteId])->first();
        if ($exception === null) {
            throw Rejection::notFound('Exception', $id);
        }
        if (! in_array($exception->status, ExceptionRules::ACTIVE_STATUSES, true)) {
            throw Rejection::stateConflict("The exception is already {$exception->status}.");
        }

        return [Event::from($ctx, 'group.exception_decided', [
            'exception_id' => $id, 'decision' => $decision, 'status' => ExceptionRules::DECISIONS[$decision], 'note' => $note,
        ])];
    }
}
