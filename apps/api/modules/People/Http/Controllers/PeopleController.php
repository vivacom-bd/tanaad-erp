<?php

declare(strict_types=1);

namespace Tanaad\People\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\People\Commands\ArchivePerson;
use Tanaad\People\Commands\EndEngagement;
use Tanaad\People\Commands\MarkAttendance;
use Tanaad\People\Commands\ReactivatePerson;
use Tanaad\People\Commands\RegisterPerson;
use Tanaad\People\Commands\RenewEngagement;
use Tanaad\People\Commands\StartEngagement;
use Tanaad\People\Models\Attendance;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Models\Person;
use Tanaad\People\Queries\Headcount;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Bodies are camelCase (prototype shape) and become the snake_case event payload; the
 * handlers validate. Answers carry the affected rows in camelCase plus the event id.
 */
final class PeopleController extends ApiController
{
    public function __construct(private readonly CommandBus $bus) {}

    public function register(Request $request): JsonResponse
    {
        $payload = $this->body($request);
        if (is_array($payload['engagement'] ?? null)) {
            $payload['engagement'] = self::snake($payload['engagement']);
        }
        $result = $this->bus->dispatch(new RegisterPerson($this->ctx($request), $payload));
        $personId = $this->id($result, 'person_id');

        return $this->answer($result, [
            'person' => self::row(Person::query()->find($personId)),
            'engagement' => self::row(Engagement::query()->where('person_id', $personId)->latest('seq')->first()),
        ]);
    }

    public function start(Request $request, string $site, string $person): JsonResponse
    {
        $result = $this->bus->dispatch(new StartEngagement($this->ctx($request), [...$this->body($request), 'person_id' => $person]));

        return $this->answer($result, ['engagement' => self::row(Engagement::query()->find($this->id($result, 'engagement_id')))]);
    }

    public function renew(Request $request, string $site, string $engagement): JsonResponse
    {
        $result = $this->bus->dispatch(new RenewEngagement($this->ctx($request), [...$this->body($request), 'engagement_id' => $engagement]));

        return $this->answer($result, ['engagement' => self::row(Engagement::query()->find($this->id($result, 'engagement_id')))]);
    }

    public function end(Request $request, string $site, string $engagement): JsonResponse
    {
        $result = $this->bus->dispatch(new EndEngagement($this->ctx($request), [...$this->body($request), 'engagement_id' => $engagement]));

        return $this->answer($result, ['engagement' => self::row(Engagement::query()->find($engagement))]);
    }

    /** A body with a reason is a correction of the existing mark. */
    public function attendance(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $reason = $body['reason'] ?? null;
        $corrects = $body['corrects_event_id'] ?? null;
        $correction = array_key_exists('reason', $body) || $corrects !== null;
        unset($body['reason'], $body['corrects_event_id']);
        $command = new MarkAttendance($this->ctx($request), $body, $correction, is_string($corrects) ? $corrects : null, is_string($reason) ? $reason : null);
        $result = $this->bus->dispatch($command);

        return $this->answer($result, ['attendance' => self::row(Attendance::query()->find($this->id($result, 'attendance_id')))]);
    }

    public function archive(Request $request, string $site, string $person): JsonResponse
    {
        $result = $this->bus->dispatch(new ArchivePerson($this->ctx($request), [...$this->body($request), 'person_id' => $person]));

        return $this->answer($result, ['person' => self::row(Person::query()->find($person))]);
    }

    public function reactivate(Request $request, string $site, string $person): JsonResponse
    {
        $result = $this->bus->dispatch(new ReactivatePerson($this->ctx($request), ['person_id' => $person]));

        return $this->answer($result, ['person' => self::row(Person::query()->find($person))]);
    }

    public function headcount(Request $request, Headcount $query): JsonResponse
    {
        $site = CurrentSite::resolve($request);
        $today = $site->calendar()->businessDay(now());
        $from = $request->query('from', $today);
        $to = $request->query('to', $from);
        $shift = $request->query('shift');
        $date = '/^\d{4}-\d{2}-\d{2}$/';
        if (! is_string($from) || ! is_string($to) || preg_match($date, $from) !== 1 || preg_match($date, $to) !== 1) {
            throw Rejection::invalidPayload(['from' => 'must be a date (YYYY-MM-DD)']);
        }

        return new JsonResponse(['data' => $query->forSite($site->id, $from, $to, is_string($shift) ? $shift : null)]);
    }

    private function ctx(Request $request): \Tanaad\Shared\Ledger\CommandContext
    {
        return $this->context($request, CurrentSite::resolve($request)->id);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        /** @var array<string, mixed> $all */
        $all = $request->json()->all();

        return self::snake($all);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function snake(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[Str::snake((string) $k)] = $v;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function row(?Model $model): ?array
    {
        if ($model === null) {
            return null;
        }
        $out = [];
        foreach ($model->attributesToArray() as $k => $v) {
            $out[Str::camel($k)] = $v;
        }

        return $out;
    }

    /** @param array<string, mixed> $body */
    private function answer(CommandResult $result, array $body): JsonResponse
    {
        return $this->created([...$body, 'event_id' => $result->primary()?->id()]);
    }

    private function id(CommandResult $result, string $key): string
    {
        $id = $result->primary()?->payload()[$key] ?? null;

        return is_string($id) ? $id : '';
    }
}
