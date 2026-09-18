<?php

namespace Gis\Support;

/**
 * What one person may do in one map.
 *
 * Pivot values on `gis_map_user`, not application roles — the application's own
 * roles and permissions stay with spatie, and the module gate
 * (`config('gis.route.permission')`) is what decides whether a user reaches the
 * GIS module at all. This enum only answers the second question, per map
 * (specification section 20).
 *
 * Deliberately a different vocabulary from `MapLayer::$access`
 * (`owner`/`edit`/`read`), which answers what a MAP may do to a LAYER. They
 * compose; neither is a substitute for the other, which is why `editor` and
 * `edit` are spelled apart.
 */
enum MapRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    /** Create, update and delete features on an unlocked, writable layer. */
    public function mayMutateFeatures(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Rename, restyle or reschema a layer — changes every map sees.
     *
     * A contributor is deliberately excluded: section 20 gives them feature
     * CRUD and nothing structural, and a restyle is global.
     */
    public function mayMutateLayers(): bool
    {
        return $this === self::Owner || $this === self::Editor;
    }

    /** Reorder, hide or fade a placement — changes only this map's view. */
    public function mayMutatePlacements(): bool
    {
        return $this === self::Owner || $this === self::Editor;
    }

    /** Measurements belong to the map and are cheap; contributors may draw them. */
    public function mayMutateMeasurements(): bool
    {
        return $this !== self::Viewer;
    }

    public function maySeeMap(): bool
    {
        return true;
    }
}
