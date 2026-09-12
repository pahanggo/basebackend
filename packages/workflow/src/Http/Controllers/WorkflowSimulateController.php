<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\Models\WorkflowDefinition;
use Workflow\Models\WorkflowDefinitionVersion;
use Workflow\Support\WorkflowSimulator;

/**
 * Backs the designer's "Test with a sample record" dry-run mode. Runs
 * against the graph JSON the request sends — the designer's current
 * in-editor state, published or not, saved or not — via an unsaved
 * WorkflowDefinitionVersion instance that's never persisted, so testing a
 * draft never requires saving it first. See WorkflowSimulator for the
 * guarantee that nothing here writes to the workflow database, dispatches a
 * real event, or executes a real action.
 */
class WorkflowSimulateController
{
    public function __invoke(Request $request, WorkflowDefinition $workflowDefinition, WorkflowSimulator $simulator): JsonResponse
    {
        $validated = $request->validate([
            'graph' => 'required|array',
            'workflowable_id' => 'required',
            'node_id' => 'required|string',
            'edge_id' => 'nullable|string',
            'inputs' => 'array',
        ]);

        $modelClass = $workflowDefinition->model;

        if (! class_exists($modelClass)) {
            return response()->json(['ok' => false, 'error' => 'This definition\'s target model no longer exists.'], 422);
        }

        $workflowable = $modelClass::find($validated['workflowable_id']);

        if (! $workflowable) {
            return response()->json(['ok' => false, 'error' => 'That sample record could not be found.'], 422);
        }

        $version = new WorkflowDefinitionVersion(['graph' => $validated['graph']]);
        $actor = backpack_auth()->user();

        if (empty($validated['edge_id'])) {
            return response()->json(['ok' => true] + $simulator->describe($version, $workflowable, $actor, $validated['node_id']));
        }

        return response()->json($simulator->advance(
            $version,
            $workflowable,
            $actor,
            $validated['node_id'],
            $validated['edge_id'],
            $validated['inputs'] ?? [],
        ));
    }
}
