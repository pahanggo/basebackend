<?php

namespace Gis\Support;

use Gis\Models\CommandEffect;

/**
 * Decides whether a stale command can be applied anyway.
 *
 * A command carrying version 4 of a row the server has moved to version 7 is
 * only a conflict if the two touched the same field. If one side moved the
 * geometry and the other set `properties.status`, both edits are wanted and
 * neither is lost — so the stale one is applied and the client is told it was
 * merged.
 *
 * This is deliberately built in v1 for a rare case, because it is exactly the
 * machinery real-time co-editing needs and it is far cheaper to prove now,
 * against occasional conflicts, than to introduce when conflicts are routine
 * (specification section 16).
 *
 * **Geometry is never merged with geometry.** That falls out of the field
 * comparison rather than being a special case: two edits to `geom` intersect,
 * so they conflict, and one side wins by the user's choice. Merging two edits
 * to the same ring is a product decision nobody has made.
 */
class FieldMerge
{
    /** @var array<string, array<int, string>> */
    protected array $cache = [];

    /**
     * Fields of this row written by any command after the given version.
     *
     * @return array<int, string>
     */
    public function changedSince(string $entity, int $entityId, int $sinceVersion): array
    {
        $key = "{$entity}:{$entityId}:{$sinceVersion}";

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $fields = CommandEffect::query()
            ->where('entity', $entity)
            ->where('entity_id', $entityId)
            ->where('version', '>', $sinceVersion)
            ->pluck('fields')
            ->flatten()
            ->unique()
            ->values()
            ->all();

        return $this->cache[$key] = $fields;
    }

    /**
     * Whether a command claiming `$claimedVersion` and writing `$fields` can be
     * folded into the row's current state.
     *
     * A row with no recorded history above the claimed version is **not**
     * mergeable: the absence of effects means the change came from somewhere
     * this log does not cover — an import, a repair, a direct write — and
     * assuming it touched nothing would silently overwrite it.
     *
     * @param  array<int, string>  $fields
     */
    public function mayMerge(string $entity, int $entityId, int $claimedVersion, array $fields): bool
    {
        $changed = $this->changedSince($entity, $entityId, $claimedVersion);

        if ($changed === []) {
            return false;
        }

        return array_intersect($changed, $fields) === [];
    }
}
