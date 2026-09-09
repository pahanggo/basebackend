<?php

namespace Workflow\Console;

use Illuminate\Console\Command;
use Workflow\Models\WorkflowTimer;
use Workflow\Support\TransitionEngine;

/**
 * Scans workflow_timers for due entries and fires their transition. Register
 * this in the consuming app's scheduler, e.g.:
 *   $schedule->command('workflow:process-timers')->everyMinute();
 */
class ProcessTimersCommand extends Command
{
    protected $signature = 'workflow:process-timers';

    protected $description = 'Fire any due workflow timers (including SLA escalation stages).';

    public function handle(TransitionEngine $engine): int
    {
        $due = WorkflowTimer::whereNull('fired_at')
            ->where('fire_at', '<=', now())
            ->with('token')
            ->get();

        foreach ($due as $timer) {
            $token = $timer->token;

            if ($token && $token->isActive()) {
                try {
                    $engine->transition($token, $timer->edge_id, [], null, true);
                } catch (\RuntimeException) {
                    // The token already moved on via a human action before this timer
                    // fired (e.g. HOD approved before the 3-day escalation) — expected,
                    // not an error, so just skip firing rather than let it abort the batch.
                }
            }

            $timer->update(['fired_at' => now()]);
        }

        $this->info("Processed {$due->count()} due timer(s).");

        return self::SUCCESS;
    }
}
