<?php

namespace Workflow\Console;

use Illuminate\Console\Command;

/**
 * Creates and migrates the separate SQLite database used by the workflow
 * engine. Mirrors app/Console/Commands/KitchenSinkInstall.php's approach for
 * the same reason: this connection's migration must NOT be auto-loaded into
 * the main app's default `migrate`/`migrate:fresh` cycle (see the comment in
 * WorkflowServiceProvider::boot()), so it's run here explicitly, scoped to
 * the "workflow" connection for both the schema changes AND the migration
 * repository (tracking) table via --database=workflow.
 */
class WorkflowInstallCommand extends Command
{
    protected $signature = 'workflow:install {--fresh : Drop and recreate the workflow database}';

    protected $description = 'Create and migrate the separate SQLite database used by the workflow engine';

    public function handle(): int
    {
        $database = config('database.connections.workflow.database');

        // In-memory databases (used in tests) have no file to touch.
        if ($database !== ':memory:') {
            if ($this->option('fresh') && file_exists($database)) {
                unlink($database);
            }

            if (! file_exists($database)) {
                touch($database);
                $this->components->info("Created {$database}");
            }
        }

        $this->call('migrate', [
            '--database' => 'workflow',
            '--path' => 'packages/workflow/src/database/migrations',
            '--force' => true,
        ]);

        $this->components->info('Workflow engine database ready.');

        return self::SUCCESS;
    }
}
