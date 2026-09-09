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
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
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
        ]);
    }
}
