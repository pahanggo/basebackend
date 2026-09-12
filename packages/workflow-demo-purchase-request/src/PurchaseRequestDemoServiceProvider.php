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
        // Registered under its own namespace so the sample header_view/
        // footer_view wired onto the 'pending_hod_review' node in
        // PurchaseRequestDemoSeeder::graph() (proving out that node
        // inspector field on a real downstream model) resolve regardless of
        // whether the demo itself is installed/enabled.
        $this->loadViewsFrom(__DIR__.'/resources/views', 'purchase-request-demo');

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
