<?php

namespace Gis\Support;

use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;

/**
 * Resolves the three terms that decide whether an edit is allowed, and does it
 * in one place so no command has to remember all three.
 *
 * | Term | Source | Question it answers |
 * | --- | --- | --- |
 * | role | `gis_map_user.role`, or owner by `gis_maps.owner_id` | What may this person do in this map? |
 * | access | `gis_map_layer.access` | What may this map do to this layer? |
 * | locked | `gis_layers.locked` | May anyone edit this layer at all? |
 *
 * The effective permission is the **narrowest** of the three. Owning a map does
 * not grant edit on a layer shared into it as `read`, and a locked layer is
 * locked for its owner too (specification section 20).
 *
 * Per-request and per-map, so it caches placements: a 500-command batch over
 * one layer would otherwise issue 500 identical lookups.
 */
class MapAccess
{
    /** @var array<int, MapLayer|null> */
    protected array $placements = [];

    /** @var array<int, Layer|null> */
    protected array $layers = [];

    public function __construct(
        public readonly Map $map,
        public readonly int $userId,
        public readonly MapRole $role,
    ) {}

    /**
     * The acting user's role in this map.
     *
     * The map's `owner_id` is authoritative and needs no pivot row — a map
     * always has an owner, and requiring the row to exist would make the owner
     * lose their own map to a missed insert.
     */
    public static function resolve(Map $map, int $userId): ?self
    {
        if ($map->owner_id === $userId) {
            return new self($map, $userId, MapRole::Owner);
        }

        $membership = $map->members()->where('user_id', $userId)->first();

        if ($membership === null) {
            return null;
        }

        return new self($map, $userId, MapRole::from($membership->role));
    }

    /** The placement of a layer in this map, or null when it is not placed here. */
    public function placement(int $layerId): ?MapLayer
    {
        return $this->placements[$layerId] ??= $this->map->placements()
            ->where('layer_id', $layerId)
            ->first();
    }

    public function placementById(int $placementId): ?MapLayer
    {
        foreach ($this->placements as $placement) {
            if ($placement?->id === $placementId) {
                return $placement;
            }
        }

        $placement = $this->map->placements()->whereKey($placementId)->first();

        if ($placement !== null) {
            $this->placements[$placement->layer_id] = $placement;
        }

        return $placement;
    }

    public function layer(int $layerId): ?Layer
    {
        return $this->layers[$layerId] ??= Layer::query()->find($layerId);
    }

    /**
     * May the acting user write features into this layer, through this map?
     *
     * All three terms, in the order they are cheapest to fail.
     */
    public function mayEditFeaturesOf(int $layerId): bool
    {
        if (! $this->role->mayMutateFeatures()) {
            return false;
        }

        return $this->layerIsWritable($layerId);
    }

    /** May the acting user change what the layer IS — name, style, schema? */
    public function mayEditLayer(int $layerId): bool
    {
        if (! $this->role->mayMutateLayers()) {
            return false;
        }

        return $this->layerIsWritable($layerId);
    }

    /** May the acting user move or hide a node in this map's tree? */
    public function mayEditPlacement(int $placementId): bool
    {
        return $this->role->mayMutatePlacements()
            && $this->placementById($placementId) !== null;
    }

    public function mayEditMeasurements(): bool
    {
        return $this->role->mayMutateMeasurements();
    }

    /**
     * Does this map own the layer outright?
     *
     * Ownership, not access, is what grants deleting a layer and changing a
     * placement's access level. There is no self-escalation path: sharing a
     * layer into a map you control does not make you its owner.
     */
    public function ownsLayer(int $layerId): bool
    {
        return $this->layer($layerId)?->owner_map_id === $this->map->id;
    }

    /**
     * May this user place the layer somewhere at the given access level?
     *
     * `read` for anything they may see; `edit` only where they ALREADY hold
     * edit rights on that layer — they own the layer's map, or hold an
     * `owner`/`edit` placement of it in a map they may open. Without the second
     * rule the permission model leaks: a user with their own map could add the
     * cadastral base as editable and rewrite 1.4 million features they were
     * only ever meant to read (specification section 8).
     */
    public static function mayTakeLayerAt(int $userId, int $layerId, string $access): bool
    {
        $layer = Layer::query()->find($layerId);

        if ($layer === null) {
            return false;
        }

        $visibleMapIds = Map::query()->visibleTo($userId)->pluck('id');

        $visible = $layer->owner_map_id === null || $visibleMapIds->contains($layer->owner_map_id);

        if (! $visible) {
            return false;
        }

        if ($access === 'read') {
            return true;
        }

        return MapLayer::query()
            ->where('layer_id', $layerId)
            ->whereIn('map_id', $visibleMapIds)
            ->whereIn('access', ['owner', 'edit'])
            ->exists();
    }

    /**
     * The placement's access and the layer's lock, without the user's role.
     *
     * A layer not placed in this map is not editable through this map at all —
     * the map is the authorization scope, and a layer id in a batch addressed
     * to a map it was never placed in is how an escalation would look.
     */
    protected function layerIsWritable(int $layerId): bool
    {
        $placement = $this->placement($layerId);

        if ($placement === null || ! in_array($placement->access, ['owner', 'edit'], true)) {
            return false;
        }

        $layer = $this->layer($layerId);

        return $layer !== null && ! $layer->locked;
    }
}
