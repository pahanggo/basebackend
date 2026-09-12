<?php

namespace Workflow\Console;

use Illuminate\Console\Command;
use Workflow\Models\WorkflowDefinition;
use Workflow\Support\WorkflowGraphValidator;

/**
 * Loads a workflow:export-shaped JSON file and writes it as a new draft (or
 * published) version — the fast, no-browser way to write a workflow. Reuses
 * the exact same draft-reuse/publish semantics as the designer's own
 * "Save draft"/"Publish" (see WorkflowDesignerController::update()): a
 * re-import updates the SAME unpublished draft row rather than piling up
 * new ones, and --publish assigns the next version number and makes it live
 * immediately, same as clicking Publish would.
 */
class WorkflowImportCommand extends Command
{
    protected $signature = 'workflow:import {file : Path to a JSON file in workflow:export\'s {definition, graph} shape}
                            {--publish : Publish immediately instead of saving as a draft}
                            {--force : Import even if the graph fails structural validation}';

    protected $description = 'Import a workflow graph JSON file as a new draft (or published) version';

    public function handle(WorkflowGraphValidator $validator): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->components->error("File not found: {$file}");

            return self::FAILURE;
        }

        $decoded = json_decode(file_get_contents($file), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->components->error('Invalid JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        if (! isset($decoded['definition'], $decoded['graph'])) {
            $this->components->error('Expected a {"definition": {...}, "graph": {...}} shape — see workflow:export\'s own output, or docs/authoring-as-code.md.');

            return self::FAILURE;
        }

        $graph = $decoded['graph'];
        $errors = $validator->validate($graph);

        if (! empty($errors) && ! $this->option('force')) {
            $this->components->error(count($errors).' problem(s) found — fix them, or re-run with --force to import anyway:');

            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $meta = $decoded['definition'];

        if (empty($meta['slug'])) {
            $this->components->error("The definition's 'slug' is required.");

            return self::FAILURE;
        }

        $definition = WorkflowDefinition::firstOrNew(['slug' => $meta['slug']]);
        $isNew = ! $definition->exists;

        if ($isNew && empty($meta['model'])) {
            $this->components->error("Definition [{$meta['slug']}] doesn't exist yet, and no 'model' was given to create it with.");

            return self::FAILURE;
        }

        $definition->name = $meta['name'] ?? $definition->name ?? $meta['slug'];
        $definition->description = $meta['description'] ?? $definition->description;

        if (! empty($meta['model'])) {
            $definition->model = $meta['model'];
        }

        $definition->save();

        if (! class_exists($definition->model)) {
            $this->components->warn("Target model [{$definition->model}] doesn't exist (yet?) — the definition was still saved.");
        }

        // Same upsert-the-one-draft-row convention as the designer's own
        // "Save draft" — a re-import never piles up extra unpublished rows.
        $version = $definition->draftVersion() ?? $definition->versions()->make(['published_at' => null]);
        $version->graph = $graph;

        if ($this->option('publish')) {
            $version->version = ($definition->versions()->max('version') ?? 0) + 1;
            $version->published_at = now();
        }

        $version->save();

        if ($this->option('publish')) {
            $definition->update(['published_version_id' => $version->id]);
            $this->components->info("Imported and published version {$version->version} of [{$definition->slug}].");
        } else {
            $this->components->info("Imported [{$definition->slug}] as a draft — not yet published (pass --publish to make it live).");
        }

        return self::SUCCESS;
    }
}
