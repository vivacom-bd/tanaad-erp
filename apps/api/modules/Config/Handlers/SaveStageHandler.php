<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Commands\SaveStage;
use Tanaad\Config\Models\Stage;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Upserts a production stage of a site, keyed by its code. */
final class SaveStageHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof SaveStage) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $siteId = $command->context()->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $payload = [
            'code' => $p->str('code', 32),
            'name' => $p->str('name'),
            'sequence' => $p->int('sequence', 1),
            'input_unit' => $p->str('input_unit', 16),
            'output_unit' => $p->str('output_unit', 16),
            'produces_label' => $p->optStr('produces_label', 16),
        ];
        $p->check();
        $id = Stage::query()->where(['site_id' => $siteId, 'code' => $payload['code']])->value('id');

        return [Event::from($command->context(), 'config.stage_saved', ['stage_id' => is_string($id) ? $id : (string) Str::uuid7(), ...$payload])];
    }
}
