<?php

namespace Workflow\Console;

use Illuminate\Console\Command;
use Workflow\Models\WorkflowDefinition;

/**
 * Writes a definition's graph (plus its own name/description/model) to a
 * plain JSON file — the fast, no-browser way to read a workflow's current
 * shape. See docs/authoring-as-code.md for the full round-trip workflow
 * this pairs with (workflow:validate, workflow:import).
 */
class WorkflowExportCommand extends Command
{
    protected $signature = 'workflow:export {slug : The WorkflowDefinition slug to export}
                            {--path= : Where to write the JSON (defaults to storage/app/workflow-exports/{slug}.json)}
                            {--draft : Export the in-progress draft instead of the published version}';

    protected $description = 'Export a workflow definition\'s graph to a JSON file';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $definition = WorkflowDefinition::where('slug', $slug)->first();

        if (! $definition) {
            $this->components->error("No workflow definition found with slug [{$slug}].");

            return self::FAILURE;
        }

        $version = $this->option('draft') ? $definition->draftVersion() : $definition->publishedVersion;

        if (! $version) {
            $this->components->error($this->option('draft')
                ? "Definition [{$slug}] has no in-progress draft."
                : "Definition [{$slug}] has no published version — pass --draft to export the draft instead.");

            return self::FAILURE;
        }

        $payload = [
            'definition' => [
                'slug' => $definition->slug,
                'name' => $definition->name,
                'description' => $definition->description,
                'model' => $definition->model,
            ],
            'graph' => $version->graph,
        ];

        $path = $this->option('path') ?: storage_path("app/workflow-exports/{$slug}.json");
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->components->info('Exported '.($this->option('draft') ? 'draft' : "version {$version->version}")." of [{$slug}] to {$path}");

        return self::SUCCESS;
    }
}
