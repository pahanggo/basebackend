<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\Support\EloquentModelFinder;

/**
 * Ajax search endpoint backing the "model_picker" field — lets a developer
 * pick a workflow definition's target model from every concrete Eloquent
 * model under app/Models instead of typing a fully-qualified class name.
 */
class WorkflowModelSearchController
{
    public function __invoke(Request $request, EloquentModelFinder $finder): JsonResponse
    {
        return response()->json([
            'data' => $finder->search($request->query('q')),
        ]);
    }
}
