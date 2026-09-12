<?php

namespace Workflow\Console;

use Illuminate\Console\Command;
use Workflow\Support\WorkflowGraphValidator;

/**
 * Structurally validates a workflow graph JSON file WITHOUT touching the
 * database — safe to run repeatedly while iterating on a hand-written (or
 * agent-written) file before ever importing it. See
 * Workflow\Support\WorkflowGraphValidator for exactly what it checks (and
 * what it deliberately doesn't — no database access means no way to confirm
 * a role/field/model actually exists).
 */
class WorkflowValidateGraphCommand extends Command
{
    protected $signature = 'workflow:validate {file : Path to a JSON file — either a bare graph, or a workflow:export-shaped {definition, graph} file}';

    protected $description = 'Structurally validate a workflow graph JSON file before importing it';

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

        // Accept either a bare graph, or workflow:export's own {definition, graph} wrapper.
        $graph = $decoded['graph'] ?? $decoded;

        $errors = $validator->validate($graph);

        if (empty($errors)) {
            $this->components->info('Graph is structurally valid.');

            return self::SUCCESS;
        }

        $this->components->error(count($errors).' problem(s) found:');

        foreach ($errors as $error) {
            $this->line("  - {$error}");
        }

        return self::FAILURE;
    }
}
