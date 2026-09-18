<?php

namespace Gis\Policies;

use Gis\Models\Map;
use Gis\Support\MapAccess;
use Gis\Support\MapRole;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Two layers, resolved together.
 *
 * The spatie permission is **necessary** — it is the gate that decides whether
 * a user reaches the GIS module at all — and the `gis_map_user` pivot is what
 * the policy actually reads. Neither is a second role system: the application
 * already has one, and inventing another inside this package would leave two
 * answers to "what may this user do" (specification section 20).
 *
 * Commands are authorised per command inside the batch by `MapAccess`, not
 * here. This policy covers the endpoints that are not commands — the listing,
 * creation, bootstrap, rename and delete of a map — which is exactly the set
 * that has no map to address a command to, or that acts on the map as a whole.
 */
class MapPolicy
{
    /** May they reach the module at all? */
    public function viewAny(Authenticatable $user): bool
    {
        return $user->can(config('gis.route.permission'));
    }

    public function create(Authenticatable $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(Authenticatable $user, Map $map): bool
    {
        return $this->viewAny($user) && $this->role($user, $map) !== null;
    }

    /** Rename and view state. */
    public function update(Authenticatable $user, Map $map): bool
    {
        return in_array($this->role($user, $map), [MapRole::Owner, MapRole::Editor], true);
    }

    /**
     * Soft delete, owner only.
     *
     * Refusing it for the currently open map is the controller's job, not this
     * one: whether a map is open is a fact about a session, not about a
     * permission, and a policy that read it would be answering a different
     * question than the one it is asked.
     */
    public function delete(Authenticatable $user, Map $map): bool
    {
        return $this->role($user, $map) === MapRole::Owner;
    }

    /**
     * Restore, administrators only — including for the map's own owner.
     *
     * An owner may delete and may not undo it themselves. That is the usual
     * shape for a destructive action with a recovery path, and it keeps the
     * recovery auditable to a small group (specification section 8).
     */
    public function restore(Authenticatable $user): bool
    {
        return $this->viewAny($user) && $user->can(config('gis.route.admin_permission'));
    }

    /** The "show deleted" listing: their own deleted maps, or anything if admin. */
    public function viewDeleted(Authenticatable $user): bool
    {
        return $this->viewAny($user);
    }

    protected function role(Authenticatable $user, Map $map): ?MapRole
    {
        if (! $this->viewAny($user)) {
            return null;
        }

        return MapAccess::resolve($map, (int) $user->getKey())?->role;
    }
}
