<?php

namespace Gis\Console;

use Illuminate\Console\Command;

class SweepCommand extends Command
{
    protected $signature = 'gis:sweep {--dry-run : Report what would be purged without deleting anything}';

    protected $description = 'Purge expired GIS soft deletes and reclaim orphaned overlay images.';

    /**
     * Three things in this package expire: soft-deleted maps and layers after
     * the retention window, rendered exports (v2), and overlay images whose
     * layer's restore window has lapsed. Nothing else runs them, so the
     * deployment needs `* * * * * php artisan schedule:run` — see the package
     * README. Without it, soft deletes become permanent retention and orphaned
     * images are never reclaimed; nothing breaks, storage grows.
     *
     * The sweep is idempotent and safe to run repeatedly. It has nothing to
     * sweep until S1 creates the tables and S5b the overlays; it is registered
     * now so the cron entry is in place before there is anything to lose.
     */
    public function handle(): int
    {
        $days = (int) config('gis.retention.soft_deleted_days');

        $this->info($this->option('dry-run')
            ? "gis:sweep (dry run): nothing to purge; retention is {$days} days."
            : "gis:sweep: nothing to purge; retention is {$days} days.");

        return self::SUCCESS;
    }
}
