<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionMethod;
use Workflow\Models\WorkflowDefinition;

/**
 * A single grouped ajax search backing every actor_rule "who can do this"
 * picker in the designer — the edge inspector, the definition-level settings
 * modal, and a node's row_actions — searches roles, permissions, the app's
 * user model, and (when a `model` param is given) that model's own
 * `callbackFunction*` methods, all in one go, returning select2's
 * grouped-results shape. Composite values ("role:name", "permission:name",
 * "user:id", "callback:methodName") let one flat selected-values array
 * decompose back into actor_rule's {roles, permissions, users, model_callback}.
 */
class WorkflowActorSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $limit = 10;

        $roleModel = config('backpack.permissionmanager.models.role');
        $permissionModel = config('backpack.permissionmanager.models.permission');
        $userModel = config('auth.providers.users.model');

        $roles = $roleModel::query()
            ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%"))
            ->limit($limit)
            ->pluck('name')
            ->map(fn ($name) => ['id' => "role:{$name}", 'text' => $name]);

        $permissions = $permissionModel::query()
            ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%"))
            ->limit($limit)
            ->pluck('name')
            ->map(fn ($name) => ['id' => "permission:{$name}", 'text' => $name]);

        $users = $userModel::query()
            ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))
            ->limit($limit)
            ->get(['id', 'name', 'email'])
            ->map(fn ($user) => ['id' => "user:{$user->id}", 'text' => "{$user->name} ({$user->email})"]);

        return response()->json([
            'results' => [
                ['text' => 'Roles', 'children' => $roles->values()],
                ['text' => 'Permissions', 'children' => $permissions->values()],
                ['text' => 'Users', 'children' => $users->values()],
                ['text' => 'Callbacks', 'children' => $this->callbacks((string) $request->query('model', ''), $term)->values()],
            ],
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: string, text: string}>
     */
    protected function callbacks(string $model, string $term): \Illuminate\Support\Collection
    {
        // Only ever reflect a class already legitimately used as some
        // workflow's target model — same reasoning as
        // WorkflowModelFieldsController's own validation, and covers a
        // downstream model living outside app/Models.
        if ($model === '' || ! class_exists($model) || ! WorkflowDefinition::where('model', $model)->exists()) {
            return collect();
        }

        return collect((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'callbackFunction'))
            ->filter(fn (string $name) => $term === '' || str_contains(strtolower($name), strtolower($term)))
            ->sort()
            ->values()
            ->map(fn (string $name) => ['id' => "callback:{$name}", 'text' => $name]);
    }
}
