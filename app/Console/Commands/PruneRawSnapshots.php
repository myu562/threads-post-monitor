<?php

namespace App\Console\Commands;

use App\Models\PostSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PruneRawSnapshots extends Command
{
    protected $signature = 'threads:prune-raw';

    protected $description = 'Null out post_snapshots.raw_json older than threads.snapshot_raw_retention_days';

    public function handle(): int
    {
        $days = max(0, (int) config('threads.snapshot_raw_retention_days', 7));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $count = PostSnapshot::query()
            ->where('captured_at', '<', $cutoff)
            ->whereNotNull('raw_json')
            ->update(['raw_json' => null]);

        $this->info("Pruned raw_json on {$count} snapshot(s) captured before {$cutoff->toDateTimeString()} UTC.");

        return self::SUCCESS;
    }
}
