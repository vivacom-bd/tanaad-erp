<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Tanaad\Config\Commands\SaveMachine;
use Tanaad\Config\Models\Machine;
use Tanaad\Config\Models\Stage;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class SaveMachineHandler implements Handler
{
    public const array STATUSES = ['active', 'arriving', 'down', 'retired'];

    public function handle(Command $command): array
    {
        if (! $command instanceof SaveMachine) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $siteId = $command->context()->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $machineId = $p->uuid('machine_id');
        $payload = [
            'machine_id' => $machineId,
            'code_key' => $p->optStr('code_key', 64),
            'code' => $p->str('code', 64),
            'kind' => $p->str('kind', 32),
            'stage_code' => $p->str('stage_code', 32),
            'status' => $p->oneOf('status', self::STATUSES),
            'make' => $p->optStr('make'),
            'model' => $p->optStr('model'),
            'commissioned' => $p->optDate('commissioned'),
            'capacity_kg_per_day' => $p->optNum('capacity_kg_per_day'),
        ];
        $p->check();

        if (! Stage::query()->where(['site_id' => $siteId, 'code' => $payload['stage_code']])->whereNull('deleted_at')->exists()) {
            throw Rejection::notFound('Stage', $payload['stage_code']);
        }
        $existing = Machine::query()->find($machineId);
        if ($existing !== null) {
            if ($existing->site_id !== $siteId) {
                throw Rejection::notFound('Machine', $machineId);
            }
            $payload['code_key'] = $existing->code_key;
        } elseif ($payload['code_key'] !== null && Machine::query()->where('code_key', $payload['code_key'])->exists()) {
            throw Rejection::duplicateFact("Code {$payload['code_key']} is already used.");
        }

        return [Event::from($command->context(), 'config.machine_saved', $payload)];
    }
}
