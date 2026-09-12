<?php

namespace WorkflowDemo\PurchaseRequest;

use Illuminate\Support\ServiceProvider;
use WorkflowDemo\PurchaseRequest\Console\PurchaseRequestDemoInstallCommand;

/**
 * A worked, end-to-end proof of the workflow engine against a real
 * downstream model — see the architecture plan's "Demo workflow: Purchase
 * Request" section. Ships in its own package and its own database, gated
 * behind config('app.workflow_demo'), so a project that doesn't want the
 * demo can leave it uninstalled entirely (its routes/sidebar link disappear,
 * and nothing under its own SQLite file is ever touched).
 */
class PurchaseRequestDemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Deliberately NOT loadMigrationsFrom() — same reason as
        // Workflow\WorkflowServiceProvider: this connection's migrations must
        // stay off the main app's default `migrate`/`migrate:fresh` cycle,
        // run instead via `workflow-demo:install` (see the install command).
        if (config('app.workflow_demo')) {
            $this->loadRoutesFrom(__DIR__.'/routes/purchase-request-demo.php');
        }

        $this->commands([
            PurchaseRequestDemoInstallCommand::class,
        ]);
    }
}
