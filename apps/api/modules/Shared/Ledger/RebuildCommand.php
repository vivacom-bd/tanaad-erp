<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Illuminate\Console\Command as ConsoleCommand;

final class RebuildCommand extends ConsoleCommand
{
    protected $signature = 'ledger:rebuild {projector? : Fully qualified projector class, or omit for all} {--from-seq=0}';

    protected $description = 'Truncate projections and replay the ledger into them in seq order';

    public function handle(Replay $replay): int
    {
        /** @var string|null $projector */
        $projector = $this->argument('projector');
        $fromSeq = (int) $this->option('from-seq');

        $this->info($projector === null ? 'Rebuilding every projector' : "Rebuilding {$projector}");
        /** @var list<class-string<Projector>>|null $only */
        $only = $projector === null ? null : [$projector];
        $count = $replay->rebuild($only, $fromSeq);
        $this->info("Replayed {$count} events.");

        return self::SUCCESS;
    }
}
