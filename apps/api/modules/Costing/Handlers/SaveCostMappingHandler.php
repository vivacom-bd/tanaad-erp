<?php

declare(strict_types=1);

namespace Tanaad\Costing\Handlers;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Costing\Commands\SaveCostMapping;
use Tanaad\Costing\Support\CostBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Maps a spend source to a cost category for one site. Sources: cash:<cash category>, payout:cash,
 * payout:non_cash, advance, collector_paid, spares_issue. The target is a code of the client's
 * expense_category list (site or group-wide, Config ref_items) or "excluded".
 */
final class SaveCostMappingHandler implements Handler
{
    public const string SOURCE_PATTERN = '/^(cash:[a-z0-9_]{1,64}|payout:cash|payout:non_cash|advance|collector_paid|spares_issue)$/';

    public function handle(Command $command): array
    {
        if (! $command instanceof SaveCostMapping) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $source = $p->str('source_key', 80);
        if ($source !== '' && preg_match(self::SOURCE_PATTERN, $source) !== 1) {
            $p->error('source_key', 'must be cash:<category>, payout:cash, payout:non_cash, advance, collector_paid or spares_issue');
        }
        $category = $p->str('cost_category', 64);
        $p->check();

        if ($category !== CostBook::EXCLUDED) {
            $known = DB::table('ref_items')->where(['list' => 'expense_category', 'code' => $category, 'active' => true])->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $siteId))->exists();
            if (! $known) {
                throw Rejection::invalidPayload(['cost_category' => 'must be an active expense category of this site, or "excluded"']);
            }
        }

        return [Event::from($ctx, 'costing.mapping_saved', [
            'mapping_id' => CostBook::mappingId($siteId, $source), 'source_key' => $source, 'cost_category' => $category,
        ])];
    }
}
