<?php

namespace Workflow\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A single grouped ajax search backing the edge inspector's "who can trigger
 * this" picker — searches roles, permissions, and the app's user model in
 * one go, returning select2's grouped-results shape. Composite values
 * ("role:name", "permission:name", "user:id") let one flat selected-values
 * array decompose back into actor_rule's {roles, permissions, users}.
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
            ],
        ]);
    }
}
