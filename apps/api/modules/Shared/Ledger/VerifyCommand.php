<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;

/**
 * ledger:verify — integrity of the event ledger. Exit 0 = nothing wrong found, 1 = failure.
 *
 * Checks: duplicate ids, received_at running backwards, per-event checksum (LedgerChecksum),
 * unsigned rows appearing after signed ones, projection cursors ahead of the ledger, and with
 * --replay that a full replay reproduces every read table exactly (done in a transaction that
 * is rolled back; run it on a restored copy for a big ledger, see infra/scripts/restore-drill.sh).
 *
 * Not verifiable: there is no hash chain, so a deleted tail or a deleted row is only visible as
 * a seq gap, and gaps also come from rolled-back transactions (InnoDB burns auto-increment values),
 * so they are reported as warnings unless --strict-gaps. Rows written before the checksum column
 * existed are counted as unverifiable.
 */
final class VerifyCommand extends ConsoleCommand
{
    protected $signature = 'ledger:verify
        {--replay : Also replay the ledger in a rolled-back transaction and compare every read table}
        {--strict-gaps : Treat seq gaps as failures}
        {--skip-tables= : Extra comma-separated tables to leave out of the replay comparison}';

    protected $description = 'Verify ledger integrity (duplicates, ordering, checksums, optional replay equality); non-zero exit on failure';

    /** Tables that replay neither rebuilds nor should be compared. */
    private const NON_PROJECTION = ['events', 'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
        'personal_access_tokens', 'password_reset_tokens', 'projection_cursors', 'reactor_runs', 'number_series', 'device_heartbeats'];

    /** received_at may legitimately interleave across concurrent transactions by a little. */
    private const ORDER_TOLERANCE_SECONDS = 60;

    public function handle(ConnectionInterface $db, Replay $replay): int
    {
        /** @var list<string> $failures */
        $failures = [];
        /** @var list<string> $warnings */
        $warnings = [];

        $this->scan($db, $failures, $warnings);

        $dupes = $db->table('events')->select('id')->groupBy('id')->havingRaw('count(*) > 1')->limit(5)->pluck('id')->all();
        if ($dupes !== []) {
            $failures[] = 'duplicate event ids: '.implode(', ', $dupes);
        }
        $max = (int) $db->table('events')->max('seq');
        $ahead = $db->table('projection_cursors')->where('last_seq', '>', $max)->pluck('projector')->all();
        if ($ahead !== []) {
            $failures[] = "projection cursors ahead of the ledger (max seq {$max}): ".implode(', ', array_map('strval', $ahead));
        }

        if ((bool) $this->option('replay')) {
            array_push($failures, ...$this->replayCompare($db, $replay));
        }

        foreach ($warnings as $w) {
            $this->warn($w);
        }
        if ($failures === []) {
            $this->info('Ledger verified: no integrity problems found.');

            return self::SUCCESS;
        }
        foreach ($failures as $f) {
            $this->error($f);
        }
        Log::critical('ledger:verify FAILED', ['failures' => $failures, 'at' => CarbonImmutable::now()->toIso8601String()]);

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     */
    private function scan(ConnectionInterface $db, array &$failures, array &$warnings): void
    {
        $prevSeq = null;
        $prevReceived = null;
        $count = 0;
        $unverifiable = 0;
        $bad = 0;
        $badSamples = [];
        $unsignedAfterSigned = 0;
        $seenSigned = false;
        $gaps = 0;
        $gapSamples = [];
        $backwards = 0;

        $db->table('events')->orderBy('seq')->chunkById(2000, function ($rows) use (&$prevSeq, &$prevReceived, &$count, &$unverifiable, &$bad, &$badSamples, &$unsignedAfterSigned, &$seenSigned, &$gaps, &$gapSamples, &$backwards): void {
            foreach ($rows as $row) {
                $count++;
                $seq = (int) $row->seq;
                if ($prevSeq !== null && $seq > $prevSeq + 1) {
                    $gaps++;
                    if (count($gapSamples) < 5) {
                        $gapSamples[] = ($prevSeq + 1).'-'.($seq - 1);
                    }
                }
                $prevSeq = $seq;

                $received = CarbonImmutable::parse($row->received_at);
                if ($prevReceived !== null && $received->diffInSeconds($prevReceived, false) > self::ORDER_TOLERANCE_SECONDS) {
                    $backwards++;
                }
                $prevReceived = $prevReceived === null || $received->isAfter($prevReceived) ? $received : $prevReceived;

                if ($row->checksum === null) {
                    $unverifiable++;
                    if ($seenSigned) {
                        $unsignedAfterSigned++;
                    }

                    continue;
                }
                $seenSigned = true;
                /* @phpstan-ignore argument.type */
                if (! hash_equals(LedgerChecksum::forRow($row), (string) $row->checksum)) {
                    $bad++;
                    if (count($badSamples) < 5) {
                        $badSamples[] = "seq {$seq}";
                    }
                }
            }
        }, 'seq');

        if ($bad > 0) {
            $failures[] = "{$bad} event(s) fail their checksum (tampered or written with another key): ".implode(', ', $badSamples);
        }
        if ($unsignedAfterSigned > 0) {
            $failures[] = "{$unsignedAfterSigned} event(s) without a checksum appear after checksummed ones (inserted outside the application?)";
        }
        if ($backwards > 0) {
            $failures[] = 'received_at runs backwards by more than '.self::ORDER_TOLERANCE_SECONDS."s in {$backwards} place(s)";
        }
        if ($gaps > 0) {
            $msg = "{$gaps} seq gap(s), e.g. ".implode(', ', $gapSamples).' (also caused by rolled-back transactions; a deleted row cannot be told apart)';
            if ((bool) $this->option('strict-gaps')) {
                $failures[] = $msg;
            } else {
                $warnings[] = $msg;
            }
        }
        if ($unverifiable > 0) {
            $warnings[] = "{$unverifiable} of {$count} event(s) predate the checksum and cannot be verified.";
        }
        $this->line("Scanned {$count} event(s).");
    }

    /**
     * @return list<string>
     */
    private function replayCompare(ConnectionInterface $db, Replay $replay): array
    {
        $skip = array_merge(self::NON_PROJECTION, array_filter(array_map('trim', explode(',', (string) $this->option('skip-tables')))));
        /** @var list<string> $tables */
        $tables = array_values(array_filter(
            array_map(fn ($r): string => (string) (((array) $r)['table_name'] ?? ''), $db->select('select table_name as table_name from information_schema.tables where table_schema = database() and table_type = ?', ['BASE TABLE'])),
            fn (string $t): bool => $t !== '' && ! in_array($t, $skip, true),
        ));
        sort($tables);

        $before = $this->fingerprints($db, $tables);
        $problems = [];
        $level = $db->transactionLevel();
        $db->beginTransaction();
        try {
            $replay->rebuild();
            $after = $this->fingerprints($db, $tables);
        } catch (\Throwable $e) {
            // A projector that TRUNCATEs would have committed implicitly; reset() must use DELETE.
            return ["replay failed: {$e->getMessage()}"];
        } finally {
            if ($db->transactionLevel() > $level) {
                $db->rollBack();
            }
        }
        foreach ($tables as $t) {
            if ($before[$t] !== $after[$t]) {
                $problems[] = "projection table {$t} differs from a replay of the ledger (live {$before[$t]}, replay {$after[$t]}; rows:crc)";
            }
        }
        $this->line('Replay compared '.count($tables).' table(s).');

        return $problems;
    }

    /**
     * Order-independent "rows:crc-sum" per table, ignoring created_at/updated_at and auto-increment ids.
     *
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function fingerprints(ConnectionInterface $db, array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $rows = 0;
            $sum = 0;
            foreach ($db->table($table)->cursor() as $row) {
                $a = (array) $row;
                unset($a['created_at'], $a['updated_at']);
                if (is_int($a['id'] ?? null)) {
                    unset($a['id']); // an auto-increment surrogate key: replay renumbers it, the facts stay the same
                }
                ksort($a);
                $rows++;
                $sum += crc32((string) json_encode($a, JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
            $out[$table] = "{$rows}:{$sum}";
        }

        return $out;
    }
}
