<?php

namespace Gis\Http\Resources;

use Gis\Casts\GeometryCast;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Models\Measurement;
use Gis\Support\BasemapProviders;
use Gis\Support\MapAccess;
use Illuminate\Support\Facades\DB;

/**
 * The bootstrap response: everything the editor needs before its first feature
 * read, and **no geometry at all**.
 *
 * That exclusion is the whole shape of this response. A map placing the
 * cadastral base holds 1.4 million features; the tree needs to know the layer
 * exists, what it is called and how it is styled, and the renderer fetches
 * coordinates by viewport afterwards.
 *
 * Each entry flattens a layer and its placement, because that is how the tree
 * renders it — but the two carry **separate ids and separate versions**.
 * `placementVersion` guards reorder and visibility; `layerVersion` guards name,
 * style and schema. Sending one where the other is meant is a conflict, which
 * is the intended way to find that mistake (specification section 7).
 */
class MapBootstrap
{
    /** @return array<string, mixed> */
    public static function make(Map $map, MapAccess $access): array
    {
        return [
            'id' => $map->id,
            'name' => $map->name,
            'slug' => $map->slug,
            'version' => $map->version,
            'role' => $access->role->value,
            'viewState' => $map->view_state,
            'layers' => self::layers($map),
            'measurements' => self::measurements($map),
            'basemaps' => self::basemaps(),
            'capabilities' => self::capabilities(),
        ];
    }

    /**
     * Saved measurements, geometry and all.
     *
     * The one place this response carries coordinates, and the exception
     * proves the rule above: a map holds a handful of measurements, not a
     * million, and they are drawn from the moment the map opens rather than
     * fetched by viewport — a measurement outside the current view is still
     * something the reader needs to find in the list.
     *
     * Capped all the same. `ST_AsGeoJSON` is read as a string and decoded
     * here, so an unbounded list would be an unbounded parse on the critical
     * path; `gis.measurements.max_per_map` is the ceiling, and the client is
     * told when it was hit rather than silently shown a subset.
     *
     * @return array<string, mixed>
     */
    protected static function measurements(Map $map): array
    {
        $limit = (int) config('gis.measurements.max_per_map');

        $rows = Measurement::query()
            ->where('map_id', $map->id)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'kind', 'value', 'unit', 'label', 'properties', 'version',
                DB::raw(GeometryCast::selectGeoJson('geom', 'geometry'))]);

        $truncated = $rows->count() > $limit;

        return [
            'truncated' => $truncated,
            'items' => $rows->take($limit)->map(fn (Measurement $measurement) => [
                'id' => $measurement->id,
                'kind' => $measurement->kind,
                'tool' => $measurement->properties['tool'] ?? null,
                'value' => (float) $measurement->value,
                'unit' => $measurement->unit,
                'label' => $measurement->label,
                'version' => $measurement->version,
                'geom' => json_decode((string) $measurement->getAttribute('geometry'), true),
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected static function layers(Map $map): array
    {
        $placements = MapLayer::query()
            ->where('map_id', $map->id)
            ->orderBy('sort_key')
            ->get();

        $layers = Layer::query()
            ->whereIn('id', $placements->pluck('layer_id'))
            ->get()
            ->keyBy('id');

        // How many maps each layer appears in, in one query rather than one per
        // row: `shared` decides which affordances the tree renders, and a tree
        // of two thousand nodes would otherwise issue two thousand counts.
        $shareCounts = MapLayer::query()
            ->whereIn('layer_id', $placements->pluck('layer_id'))
            ->selectRaw('layer_id, COUNT(*) as placements')
            ->groupBy('layer_id')
            ->pluck('placements', 'layer_id');

        return $placements->map(function (MapLayer $placement) use ($layers, $shareCounts, $map) {
            $layer = $layers->get($placement->layer_id);

            if ($layer === null) {
                return null;
            }

            return [
                'placementId' => $placement->id,
                'layerId' => $layer->id,
                'parentId' => $placement->parent_id,
                'sortKey' => $placement->sort_key,
                'kind' => $layer->kind,
                'name' => $layer->name,
                'visible' => $placement->visible,
                'opacity' => $placement->opacity,
                'minZoom' => $placement->min_zoom,
                'maxZoom' => $placement->max_zoom,
                // How this map splits the layer into sublayers, or null. On the
                // placement rather than in `style` because a style write
                // refuses a locked layer, and every imported layer is locked.
                'classification' => $placement->classification,
                'locked' => $layer->locked,
                'access' => $placement->access,
                'shared' => (int) $shareCounts->get($layer->id, 1) > 1,
                'ownerMapId' => $layer->owner_map_id,
                'ownedHere' => $layer->owner_map_id === $map->id,
                'style' => $layer->style,
                'attrSchema' => $layer->attr_schema,
                'sourceConfig' => $layer->source_config,
                'featureCount' => $layer->feature_count,
                'extent' => self::extent($layer),
                'placementVersion' => $placement->version,
                'layerVersion' => $layer->version,
            ];
        })->filter()->values()->all();
    }

    /**
     * The layer's bounding box, for "zoom to layer".
     *
     * A box rather than the geometry: the tree only ever needs to fit the map
     * to it, and the column is nullable metadata with no spatial index, so
     * there is nothing here worth shipping in full. Longitude first, as
     * everything on this wire is.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    protected static function extent(Layer $layer): ?array
    {
        $extent = $layer->extent;

        if ($extent === null) {
            return null;
        }

        $ring = $extent->exteriorRing();
        $xs = [];
        $ys = [];

        foreach ($ring->points() as $point) {
            $xs[] = $point->x();
            $ys[] = $point->y();
        }

        return $xs === [] ? null : [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * The basemap list, the tile template and the attribution together.
     *
     * The template comes from `config('services.map_tiles')`, which the
     * latlng_picker CRUD field already uses — this package defines no tile URL
     * of its own, so a deployment that repoints its tiles repoints every map in
     * the application at once. Note its `{x}/{y}/{z}` order, which is not
     * Leaflet's default and is why the client must take the template rather
     * than assemble one (specification section 13).
     *
     * @return array<string, mixed>
     */
    protected static function basemaps(): array
    {
        $providers = (new BasemapProviders)->all();

        // Only the featured ids the service actually offers: one removed
        // upstream must not leave a preview tile that 404s.
        $featured = [];

        foreach ((array) config('gis.basemaps.featured') as $id => $label) {
            if (in_array($id, $providers['basemaps'], true)) {
                $featured[] = ['id' => $id, 'label' => __($label)];
            }
        }

        return [
            ...$providers,
            'featured' => $featured,
            'default' => config('gis.basemaps.default'),
            'urlTemplate' => config('services.map_tiles.url'),
            'attribution' => config('services.map_tiles.attribution'),
        ];
    }

    /**
     * What the server can actually do, read at runtime.
     *
     * The client branches on these rather than on a client-side config, so it
     * never drifts from the deployment it is talking to — whether GEOS is
     * available decides where a buffer is computed, and guessing wrong means
     * either a refused request or a wrong answer.
     *
     * @return array<string, mixed>
     */
    public static function capabilities(): array
    {
        return [
            // Asked of the service rather than assumed, so a deployment
            // without the binary tells the client and the client stops
            // offering operations it cannot complete — rather than shipping
            // different code to different deployments.
            'geos' => app(\Gis\Geometry\GeometryService::class)->available(),
            'inlineOpVertexLimit' => (int) config('gis.geometry.inline_op_vertex_limit'),
            'maxBatch' => (int) config('gis.write.max_batch'),
            'maxFeaturesPerResponse' => (int) config('gis.read.max_features_per_response'),
            'binaryFeatures' => true,
            'editMinZoom' => (int) config('gis.read.edit_min_zoom'),
            'minAreaPx' => (float) config('gis.read.min_area_px'),
        ];
    }
}
