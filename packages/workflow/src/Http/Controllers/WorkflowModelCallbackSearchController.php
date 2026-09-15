<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionMethod;
use Workflow\Models\WorkflowDefinition;

/**
 * Ajax search backing the "model callback" pickers — both the actor_rule's
 * model_callback and the model_callback precondition type point at a public
 * method already declared on the workflow's target model, named
 * `callbackFunction*` by convention, rather than accepting a freeform class
 * or method name typed into the editor (see "Rule & actor authoring UX").
 */
class WorkflowModelCallbackSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $model = (string) $request->query('model');
        $term = strtolower(trim((string) $request->query('q', '')));

        // Validated against WorkflowDefinition rather than
        // EloquentModelFinder (which only scans app/Models) — a downstream
        // model can live in its own package (e.g. the Purchase Request
        // demo's), and only needs to already be some workflow's target to
        // be reflectable here. See WorkflowModelFieldsController/
        // WorkflowActorSearchController for the same fix, made earlier.
        if ($model === '' || ! class_exists($model) || ! WorkflowDefinition::where('model', $model)->exists()) {
            return response()->json(['results' => []]);
        }

        $methods = collect((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'callbackFunction'))
            ->filter(fn (string $name) => $term === '' || str_contains(strtolower($name), $term))
            ->sort()
            ->values()
            ->map(fn (string $name) => ['id' => $name, 'text' => $name]);

        return response()->json(['results' => $methods]);
    }
}
