<?php

namespace Workflow;

use Illuminate\Support\ServiceProvider;
use Workflow\Registries\WorkflowActionRegistry;
use Workflow\Registries\WorkflowPreconditionRegistry;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/workflow.php', 'workflow');

        $this->app->singleton(WorkflowActionRegistry::class);
        $this->app->singleton(WorkflowPreconditionRegistry::class);
    }

    public function boot(): void
    {
        // Deliberately NOT loadMigrationsFrom(): this connection's migration
        // repository must stay on the "workflow" connection too (see
        // Console\WorkflowInstallCommand), the same reason kitchensink's own
        // migrations aren't auto-loaded either — otherwise a plain `migrate`/
        // `migrate:fresh` on the main app's default connection wipes its own
        // migrations table, "forgets" this migration ran, and then fails with
        // "table already exists" when it tries to recreate tables that were
        // never dropped (they live on a separate connection/file untouched
        // by a default-connection migrate:fresh).
        $this->loadViewsFrom(__DIR__.'/resources/views', 'workflow');
        $this->loadRoutesFrom(__DIR__.'/routes/workflow.php');

        $this->publishes([
            __DIR__.'/config/workflow.php' => config_path('workflow.php'),
        ], 'workflow-config');

        $actions = $this->app->make(WorkflowActionRegistry::class);
        foreach (config('workflow.actions', []) as $key => $class) {
            $actions->register($key, $class);
        }

        $preconditions = $this->app->make(WorkflowPreconditionRegistry::class);
        foreach (config('workflow.preconditions', []) as $key => $class) {
            $preconditions->register($key, $class);
        }

        $this->commands([
            \Workflow\Console\ProcessTimersCommand::class,
            \Workflow\Console\WorkflowInstallCommand::class,
        ]);
    }
}
