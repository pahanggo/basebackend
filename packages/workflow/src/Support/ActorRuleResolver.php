<?php

namespace Workflow\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Evaluates an edge's actor_rule — {roles, permissions, users, model_callback,
 * match} — against a specific user. Built into the engine rather than a
 * registered type, since every workflow needs this, unlike the genuinely
 * extensible precondition/action types.
 */
class ActorRuleResolver
{
    /**
     * @param  array<string, mixed>|null  $rule
     * @param  Model|null  $model  the workflowable the edge belongs to — only
     *                             needed when the rule declares a model_callback.
     */
    public function allows(?array $rule, ?Authenticatable $user, ?Model $model = null): bool
    {
        if (empty($rule)) {
            // No actor_rule declared: anyone may trigger it.
            return true;
        }

        if (! $user) {
            return false;
        }

        $checks = [];

        if (! empty($rule['users'])) {
            $checks[] = in_array($user->getAuthIdentifier(), $rule['users']);
        }

        if (! empty($rule['roles']) && method_exists($user, 'hasAnyRole')) {
            $checks[] = $user->hasAnyRole($rule['roles']);
        }

        if (! empty($rule['permissions']) && method_exists($user, 'hasAnyPermission')) {
            $checks[] = $user->hasAnyPermission($rule['permissions']);
        }

        if (! empty($rule['model_callback']) && $model && method_exists($model, $rule['model_callback'])) {
            $checks[] = (bool) $model->{$rule['model_callback']}($user);
        }

        if (empty($checks)) {
            return true;
        }

        $match = $rule['match'] ?? 'any';

        return $match === 'all'
            ? ! in_array(false, $checks, true)
            : in_array(true, $checks, true);
    }

    /**
     * Resolve the concrete list of {actor_type, actor_id} pairs eligible for an
     * actor_rule, for populating workflow_instance_pending_actors. Roles and
     * permissions are stored by id (not expanded to member users) so the "My
     * Tasks" widget query stays cheap and self-updating as role membership changes.
     *
     * @param  array<string, mixed>|null  $rule
     * @return array<int, array{actor_type: string, actor_id: int}>
     */
    public function pendingActorRows(?array $rule): array
    {
        if (empty($rule)) {
            return [];
        }

        $rows = [];

        foreach ($rule['roles'] ?? [] as $roleId) {
            $rows[] = ['actor_type' => 'role', 'actor_id' => $roleId];
        }

        foreach ($rule['permissions'] ?? [] as $permissionId) {
            $rows[] = ['actor_type' => 'permission', 'actor_id' => $permissionId];
        }

        foreach ($rule['users'] ?? [] as $userId) {
            $rows[] = ['actor_type' => 'user', 'actor_id' => $userId];
        }

        return $rows;
    }
}
