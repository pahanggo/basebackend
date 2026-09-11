<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionMethod;
use Workflow\Support\EloquentModelFinder;

/**
 * Ajax search backing the "model callback" pickers — both the actor_rule's
 * model_callback and the model_callback precondition type point at a public
 * method already declared on the workflow's target model, named
 * `callbackFunction*` by convention, rather than accepting a freeform class
 * or method name typed into the editor (see "Rule & actor authoring UX").
 */
class WorkflowModelCallbackSearchController
{
    public function __invoke(Request $request, EloquentModelFinder $finder): JsonResponse
    {
        $model = (string) $request->query('model');
        $term = strtolower(trim((string) $request->query('q', '')));

        // Only ever reflect a class this app's own EloquentModelFinder
        // already recognizes as a concrete model under app/Models — never an
        // arbitrary attacker-supplied class name.
        if ($model === '' || ! in_array($model, $finder->all(), true)) {
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
