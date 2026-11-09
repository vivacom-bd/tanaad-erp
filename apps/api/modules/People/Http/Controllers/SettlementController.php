<?php

declare(strict_types=1);

namespace Tanaad\People\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tanaad\Identity\Http\CurrentSite;
use Tanaad\People\Commands\ApproveSettlement;
use Tanaad\People\Commands\CancelSettlementRun;
use Tanaad\People\Commands\CreateSettlementRun;
use Tanaad\People\Commands\GiveAdvance;
use Tanaad\People\Commands\RecordPayout;
use Tanaad\People\Models\Advance;
use Tanaad\People\Models\Payout;
use Tanaad\People\Models\SettlementLine;
use Tanaad\People\Models\SettlementRun;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandResult;
use Tanaad\Shared\Ledger\Rejection;

/** Settlement runs, payouts and advances. camelCase bodies in and out, like PeopleController. */
final class SettlementController extends ApiController
{
    public function __construct(private readonly CommandBus $bus) {}

    public function create(Request $request): JsonResponse
    {
        $body = $this->body($request);
        if (is_array($body['bonuses'] ?? null)) {
            $body['bonuses'] = array_map(fn (mixed $b): mixed => is_array($b) ? self::snake($b) : $b, $body['bonuses']);
        }
        $result = $this->bus->dispatch(new CreateSettlementRun($this->ctx($request), $body));

        return $this->answer($result, $this->runBody($this->id($result, 'settlement_run_id')));
    }

    public function index(Request $request): JsonResponse
    {
        $siteId = CurrentSite::resolve($request)->id;
        $runs = SettlementRun::query()->where('site_id', $siteId)->orderByDesc('period_to')->orderByDesc('id')->get();
        $lines = SettlementLine::query()->whereIn('settlement_run_id', $runs->pluck('id'))->orderBy('id')->get()->groupBy('settlement_run_id');
        $payouts = Payout::query()->whereIn('settlement_run_id', $runs->pluck('id'))->orderBy('id')->get()->groupBy('settlement_run_id');

        return new JsonResponse(['data' => $runs->map(fn (SettlementRun $r): array => [
            'run' => self::row($r),
            'lines' => $lines->get($r->id, collect())->map(fn (SettlementLine $l): ?array => self::row($l))->values()->all(),
            'payouts' => $payouts->get($r->id, collect())->map(fn (Payout $p): ?array => self::row($p))->values()->all(),
        ])->values()->all()]);
    }

    public function advances(Request $request): JsonResponse
    {
        $rows = Advance::query()->where('site_id', CurrentSite::resolve($request)->id)->orderByDesc('given_on')->orderByDesc('id')->get();

        return new JsonResponse(['data' => $rows->map(fn (Advance $a): ?array => self::row($a))->values()->all()]);
    }

    public function show(Request $request, string $site, string $run): JsonResponse
    {
        $body = $this->runBody($run, CurrentSite::resolve($request)->id);
        if ($body['run'] === null) {
            throw Rejection::notFound('Settlement run', $run);
        }

        return new JsonResponse(['data' => $body]);
    }

    public function approve(Request $request, string $site, string $run): JsonResponse
    {
        $result = $this->bus->dispatch(new ApproveSettlement($this->ctx($request), ['settlement_run_id' => $run]));

        return $this->answer($result, ['run' => self::row(SettlementRun::query()->find($run))]);
    }

    public function cancel(Request $request, string $site, string $run): JsonResponse
    {
        $result = $this->bus->dispatch(new CancelSettlementRun($this->ctx($request), [...$this->body($request), 'settlement_run_id' => $run]));

        return $this->answer($result, $this->runBody($run));
    }

    public function payout(Request $request, string $site, string $run): JsonResponse
    {
        $result = $this->bus->dispatch(new RecordPayout($this->ctx($request), [...$this->body($request), 'settlement_run_id' => $run]));

        return $this->answer($result, ['payout' => self::row(Payout::query()->find($this->id($result, 'payout_id')))]);
    }

    public function advance(Request $request): JsonResponse
    {
        $result = $this->bus->dispatch(new GiveAdvance($this->ctx($request), $this->body($request)));

        return $this->answer($result, ['advance' => self::row(Advance::query()->find($this->id($result, 'advance_id')))]);
    }

    /** @return array{run: array<string, mixed>|null, lines: array<int, array<string, mixed>|null>} */
    private function runBody(string $runId, ?string $siteId = null): array
    {
        $run = SettlementRun::query()->where('id', $runId)->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))->first();

        return [
            'run' => self::row($run),
            'lines' => $run === null ? [] : SettlementLine::query()->where('settlement_run_id', $runId)->orderBy('id')->get()
                ->map(fn (SettlementLine $l): ?array => self::row($l))->values()->all(),
        ];
    }

    private function ctx(Request $request): CommandContext
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
