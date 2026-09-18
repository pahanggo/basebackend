<?php

namespace Gis\Console;

use Gis\Models\CommandEffect;
use Gis\Models\CommandLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
        $logDays = (int) config('gis.retention.command_log_days');
        $before = Carbon::now()->subDays($logDays);

        $expired = CommandLog::query()->where('created_at', '<', $before);
        $count = (clone $expired)->count();

        if ($this->option('dry-run')) {
            $this->info("gis:sweep (dry run): {$count} command log entries older than {$logDays} days; soft-delete retention is {$days} days.");

            return self::SUCCESS;
        }

        // Effects first: they reference the log, and a deleted log with live
        // effects would leave the conflict merge reading history whose commands
        // are gone.
        CommandEffect::query()
            ->whereIn('log_id', (clone $expired)->select('id'))
            ->delete();

        $expired->delete();

        $this->info("gis:sweep: purged {$count} command log entries older than {$logDays} days; soft-delete retention is {$days} days.");

        return self::SUCCESS;
    }
}
