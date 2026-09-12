<?php

namespace Workflow;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Workflow\Registries\WorkflowActionRegistry;
use Workflow\Registries\WorkflowPreconditionRegistry;
use Workflow\Support\FieldPolicyResolver;

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

        $this->applyFieldPolicyToEditForm();
    }

    /**
     * Wires FieldPolicyResolver into every HasWorkflow model's own Backpack
     * update form — "free" for any downstream CrudController, no per-
     * controller code needed. This has to be a view composer rather than an
     * operation() closure registered from WorkflowOperation: those closures
     * run BEFORE the controller's own setupUpdateOperation() adds its
     * fields (see CrudController::setupConfigurationForCurrentOperation()),
     * so there'd be nothing yet to reorder/mark at that point. A composer
     * on the actual edit view fires only once the fields are fully built
     * and about to render, which is the first point field policy can
     * meaningfully apply.
     */
    protected function applyFieldPolicyToEditForm(): void
    {
        View::composer('crud::edit', function ($view) {
            $data = $view->getData();
            $crud = $data['crud'] ?? null;
            $entry = $data['entry'] ?? null;

            if (! $crud || ! $entry || ! in_array(HasWorkflow::class, class_uses_recursive($entry), true)) {
                return;
            }

            $instance = $entry->workflowInstance();

            if (! $instance) {
                return;
            }

            $fields = $this->app->make(FieldPolicyResolver::class)->apply($crud->fields(), $instance);
            $crud->setOperationSetting('fields', $fields);
        });
    }
}
