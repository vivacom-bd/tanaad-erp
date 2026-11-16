<?php

declare(strict_types=1);

namespace Tanaad\Group\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Group\Authorization\GroupAuthorizer;
use Tanaad\Group\Commands\DecideException;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Group\Models\BusinessException;
use Tanaad\Group\Queries\ExceptionQuery;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;

/** CEO Approvals. Reads take the prototype's camelCase shape; writes map it to the snake_case payload. */
final class ExceptionController extends ApiController
{
    private const array RAISE_FIELDS = [
        'exceptionId' => 'exception_id', 'kind' => 'kind', 'severity' => 'severity', 'title' => 'title', 'detail' => 'detail',
        'subjectRef' => 'subject_ref', 'ownerRole' => 'owner_role', 'facts' => 'facts',
    ];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly ExceptionQuery $query,
        private readonly GroupAuthorizer $authorizer,
    ) {}

    /** Group-wide, for the CEO and group admin. ?kind=&site=&status= */
    public function all(Request $request): JsonResponse
    {
        if (! $this->authorizer->canReadAll($this->user($request))) {
            throw Rejection::forbidden('Only group roles see every site\'s exceptions.');
        }
        $site = $request->query('site');
        $siteIds = null;
        if (is_string($site) && $site !== '') {
            $siteIds = [$this->query->siteId($site) ?? throw Rejection::notFound('Site', $site)];
        }

        return new JsonResponse(['data' => $this->query->list($siteIds, $this->filter($request, 'kind'), $this->filter($request, 'status'))]);
    }

    public function site(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        if (! $this->authorizer->canRead($this->user($request), $siteId)) {
            throw Rejection::forbidden('Only group roles, or this site\'s manager or supervisor, may read its exceptions.');
        }

        return new JsonResponse(['data' => $this->query->list([$siteId], $this->filter($request, 'kind'), $this->filter($request, 'status'))]);
    }

    public function raise(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $payload = [];
        foreach (self::RAISE_FIELDS as $in => $key) {
            if ($request->has($in)) {
                $payload[$key] = $request->input($in);
            }
        }
        $result = $this->bus->dispatch(new RaiseException($this->context($request, $siteId), $payload));
        if ($result->events === []) {
            throw Rejection::duplicateFact('An open exception of this kind already exists for this subject.');
        }

        return $this->answer($result);
    }

    public function decide(Request $request, string $site, string $exception): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $result = $this->bus->dispatch(new DecideException($this->context($request, $siteId), [
            'exception_id' => $exception, 'decision' => $request->input('decision'), 'note' => $request->input('note'),
        ]));

        return $this->answer($result);
    }

    private function answer(CommandResult $result): JsonResponse
    {
        $event = $result->events[0] ?? throw new \LogicException('No event in the result.');
        $id = $event->payload()['exception_id'] ?? null;
        $row = BusinessException::query()->findOrFail(is_string($id) ? $id : '');

        return $this->created(['exception' => ExceptionQuery::shape($row), 'event_id' => $event->id()]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('Sign in first.');
        }

        return $user->loadMissing('siteRoles');
    }

    private function filter(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) && $v !== '' ? $v : null;
    }
}
