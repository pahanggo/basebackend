<?php

namespace WorkflowDemo\PurchaseRequest\Console;

use Illuminate\Console\Command;
use WorkflowDemo\PurchaseRequest\Database\Seeders\PurchaseRequestDemoSeeder;

/**
 * Creates, migrates, and seeds the demo's own SQLite database — mirrors
 * app/Console/Commands/KitchenSinkInstall.php's and
 * Workflow\Console\WorkflowInstallCommand's install/toggle pattern. Also
 * ensures the engine's own "workflow" database is installed first (the
 * demo's WorkflowDefinition/Instance rows live there), so a fresh checkout
 * only ever needs this one command.
 */
class PurchaseRequestDemoInstallCommand extends Command
{
    protected $signature = 'workflow-demo:install {--fresh : Drop and recreate the demo database}';

    protected $description = 'Create, migrate and seed the separate SQLite database used by the Purchase Request workflow demo';

    public function handle(): int
    {
        if (! config('app.workflow_demo')) {
            $this->components->warn('The Purchase Request demo is disabled in config/app.php. Set "workflow_demo" to true first.');

            return self::FAILURE;
        }

        // The demo's WorkflowDefinition/Instance/Token rows live on the
        // engine's own "workflow" connection — make sure that database
        // exists too, so `workflow-demo:install` alone is enough on a fresh
        // checkout without a separate `workflow:install` step.
        $this->call('workflow:install', $this->option('fresh') ? ['--fresh' => true] : []);

        $database = config('database.connections.purchase_request_demo.database');

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
            '--database' => 'purchase_request_demo',
            '--path' => 'packages/workflow-demo-purchase-request/src/Database/migrations',
            '--force' => true,
        ]);

        $this->call('db:seed', ['--class' => PurchaseRequestDemoSeeder::class, '--force' => true]);

        $this->components->info('Purchase Request demo ready at '.backpack_url('purchase-requests'));

        return self::SUCCESS;
    }
}
