<?php

namespace Gis\Http\Controllers\Api;

use Gis\Http\ProblemResponse;
use Gis\Http\Resources\MapBootstrap;
use Gis\Models\CommandLog;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Models\MapUser;
use Gis\Support\IdempotencyStore;
use Gis\Support\MapAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Maps: list, create, bootstrap, rename, delete.
 *
 * These sit outside the command envelope for one reason each. Listing and
 * bootstrap are reads. Creation has no map to address a command to — it still
 * carries `(clientId, seq)` and is still idempotent, because a duplicate map is
 * a worse failure than a duplicate feature: the user may not notice until both
 * have diverged (specification section 7).
 *
 * Rename and delete are here rather than as commands because they act on the
 * map itself rather than on its contents, and because delete has to be refused
 * for a map that is currently open — a fact about a session, which a command
 * batch addressed to that map is in no position to check.
 */
class MapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->user()->cannot('viewAny', Map::class)) {
            return $this->forbidden('You may not use the GIS module.');
        }

        $deleted = $request->boolean('deleted');

        if ($deleted && ! $user->can('viewDeleted', Map::class)) {
            return $this->forbidden('You may not list deleted maps.');
        }

        $query = Map::query()->visibleTo((int) $user->getKey());

        if ($deleted) {
            // Their own deleted maps, or anything within the window for an
            // administrator — who is the only one who can restore them anyway.
            $query = $user->can('restore', Map::class)
                ? $query->onlyTrashed()
                : $query->onlyTrashed()->where('owner_id', $user->getKey());
        }

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $sort = (string) $request->query('sort', '-updated_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        $query->orderBy(in_array($column, ['name', 'updated_at', 'created_at'], true) ? $column : 'updated_at', $direction);

        $maps = $query->paginate(perPage: 25);

        // Layer and feature counts for the page, in two queries rather than two
        // per row.
        $ids = collect($maps->items())->pluck('id');

        $layerCounts = MapLayer::query()
            ->whereIn('map_id', $ids)
            ->selectRaw('map_id, COUNT(*) as layers')
            ->groupBy('map_id')
            ->pluck('layers', 'map_id');

        // Owned layers only. Summing shared ones would print the 1.4 million
        // cadastral base on every row and tell the user nothing about the work
        // done in that map (specification section 8).
        $featureCounts = Layer::query()
            ->whereIn('owner_map_id', $ids)
            ->selectRaw('owner_map_id, SUM(feature_count) as features')
            ->groupBy('owner_map_id')
            ->pluck('features', 'owner_map_id');

        $roles = MapUser::query()
            ->whereIn('map_id', $ids)
            ->where('user_id', $user->getKey())
            ->pluck('role', 'map_id');

        return new JsonResponse([
            'data' => collect($maps->items())->map(fn (Map $map) => [
                'id' => $map->id,
                'name' => $map->name,
                'slug' => $map->slug,
                'layerCount' => (int) $layerCounts->get($map->id, 0),
                'featureCount' => (int) $featureCounts->get($map->id, 0),
                'updatedAt' => $map->updated_at?->toIso8601String(),
                'ownerId' => $map->owner_id,

                // The row carries the acting user's role so the client knows
                // which actions to offer without a second request.
                'role' => $map->owner_id === $user->getKey() ? 'owner' : $roles->get($map->id),
                'deletedAt' => $map->deleted_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'page' => $maps->currentPage(),
                'perPage' => $maps->perPage(),
                'total' => $maps->total(),
            ],
        ]);
    }

    /**
     * Create a map, optionally seeded from another.
     *
     * The response is the same shape as the bootstrap read, so the client
     * hydrates its store from the creation response with no follow-up `GET`.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->cannot('create', Map::class)) {
            return $this->forbidden('You may not create maps.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'clientId' => ['required', 'string', 'max:64'],
            'seq' => ['required', 'integer', 'min:0'],
            'viewState' => ['sometimes', 'array'],
            'from.kind' => ['sometimes', 'in:copy'],
            'from.mapId' => ['required_with:from.kind', 'integer'],
        ]);

        $idempotency = new IdempotencyStore;
        $replayed = $idempotency->replayAny($validated['clientId'], (int) $validated['seq']);

        if ($replayed !== null) {
            return new JsonResponse($replayed, 200);
        }

        $userId = (int) $request->user()->getKey();
        $source = null;

        if (($validated['from']['kind'] ?? null) === 'copy') {
            $source = Map::query()->find($validated['from']['mapId']);

            if ($source === null || $request->user()->cannot('view', $source)) {
                return $this->forbidden('You may not copy that map.');
            }
        }

        $map = DB::connection(config('gis.connection'))->transaction(function () use ($validated, $userId, $source, $request) {
            $map = Map::query()->create([
                'owner_id' => $userId,
                'name' => $validated['name'],
                'view_state' => $validated['viewState'] ?? [
                    'center' => config('gis.default_view.center'),
                    'zoom' => config('gis.default_view.zoom'),
                    'basemap' => config('gis.basemaps.default'),
                    'overlays' => [],
                ],
                'version' => 1,
            ]);

            if ($source !== null) {
                $this->copyPlacements($source, $map, $userId);
            }

            // The creation is logged like a batch, so the idempotency lookup
            // has something to find and the map's history starts where the map
            // does rather than at its first edit.
            CommandLog::query()->create([
                'map_id' => $map->id,
                'map_version' => 1,
                'client_id' => $validated['clientId'],
                'seq' => (int) $validated['seq'],
                'user_id' => $userId,
                'commands' => [['op' => 'map.create', 'name' => $validated['name']]],
                'response' => ['mapId' => $map->id],
                'created_at' => now(),
            ]);

            return $map;
        });

        $access = MapAccess::resolve($map, $userId);

        return new JsonResponse(MapBootstrap::make($map->fresh(), $access), 201);
    }

    public function show(Request $request, Map $map): JsonResponse
    {
        if ($request->user()->cannot('view', $map)) {
            return $this->forbidden('You may not open that map.');
        }

        return new JsonResponse(MapBootstrap::make($map, MapAccess::resolve($map, (int) $request->user()->getKey())));
    }

    /** Rename and view state, guarded on the map's version. */
    public function update(Request $request, Map $map): JsonResponse
    {
        if ($request->user()->cannot('update', $map)) {
            return $this->forbidden('You may not change that map.');
        }

        $validated = $request->validate([
            'version' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'viewState' => ['sometimes', 'array'],
        ]);

        if ((int) $validated['version'] !== $map->version) {
            return ProblemResponse::make('version_conflict', 409, 'Version conflict', 'The map changed since you read it.', [
                'conflicts' => [[
                    'entity' => 'map',
                    'id' => $map->id,
                    'yourVersion' => (int) $validated['version'],
                    'serverVersion' => $map->version,
                    'server' => ['name' => $map->name, 'viewState' => $map->view_state],
                ]],
            ]);
        }

        $map->forceFill(array_filter([
            'name' => $validated['name'] ?? null,
            'view_state' => $validated['viewState'] ?? null,
        ], fn ($value) => $value !== null) + ['version' => $map->version + 1])->save();

        return new JsonResponse(['id' => $map->id, 'version' => $map->version]);
    }

    /**
     * Where the map opens next time: centre, zoom, basemap, overlays and
     * which panels are open.
     *
     * **Deliberately not `update()`, and deliberately unversioned.** Two
     * reasons, and both matter.
     *
     * The map's `version` is the replay sequence for its command log — every
     * batch bumps it once and `GET /maps/{map}/commands?since=n` is a range
     * scan over it. Bumping it for a pan would thread holes through that
     * sequence and invalidate the version every other client is holding, many
     * times a minute, for something nobody is editing.
     *
     * And a conflict dialog for a pan would be absurd. Two people looking at
     * one map do not need to agree on where it opens; the last one to move it
     * wins and neither loses anything. Optimistic locking is for content.
     *
     * Restricted to the roles that may change the map, so a viewer moving
     * around does not move everyone's starting view.
     */
    public function view(Request $request, Map $map): JsonResponse
    {
        if ($request->user()->cannot('update', $map)) {
            return $this->forbidden('You may not change that map.');
        }

        $validated = $request->validate([
            'center' => ['required', 'array', 'size:2'],
            'center.0' => ['required', 'numeric', 'between:-180,180'],
            'center.1' => ['required', 'numeric', 'between:-90,90'],
            'zoom' => ['required', 'integer', 'between:0,24'],
            'basemap' => ['sometimes', 'string', 'max:64'],
            'overlays' => ['sometimes', 'array'],
            'overlays.*' => ['string', 'max:64'],

            // Which panels are open. Chrome rather than geography, but it is
            // the same question the rest of this payload answers — how the map
            // should look when it is opened again — and keeping it here means
            // one unversioned write rather than a second store beside it.
            'panels' => ['sometimes', 'array'],
            'panels.sidebar' => ['sometimes', 'boolean'],
            'panels.controls' => ['sometimes', 'boolean'],
            'panels.layers' => ['sometimes', 'boolean'],
        ]);

        // Merged, so a client that knows only where it is looking does not
        // erase the basemap the user chose.
        $map->forceFill([
            'view_state' => [...($map->view_state ?? []), ...$validated],
        ])->saveQuietly();

        return new JsonResponse(null, 204);
    }

    /**
     * Soft delete, restorable for 30 days by an administrator.
     *
     * Refused for the map the requesting client currently has open, which the
     * client has to declare: only it knows, and leaving the editor holding a
     * deleted map is a state nothing else recovers from.
     */
    public function destroy(Request $request, Map $map): JsonResponse
    {
        if ($request->user()->cannot('delete', $map)) {
            return $this->forbidden('Only the map owner may delete it.');
        }

        if ((int) $request->query('openMapId', 0) === $map->id) {
            return ProblemResponse::make(
                'map_open',
                409,
                'Map is open',
                'Close or switch away from this map before deleting it.',
            );
        }

        $map->delete();

        return new JsonResponse(['id' => $map->id, 'deletedAt' => $map->fresh()->deleted_at?->toIso8601String()]);
    }

    /**
     * Copying shares layers; it never duplicates features, at any size.
     *
     * A statewide map holding 1.4 million features copies in a handful of rows
     * and completes instantly. An earlier design duplicated features up to a
     * 20,000-row cap, which against real data would have rejected every copy —
     * shared layers make the question disappear rather than answer it
     * (specification section 7).
     */
    protected function copyPlacements(Map $source, Map $target, int $userId): void
    {
        $placements = MapLayer::query()->where('map_id', $source->id)->orderBy('sort_key')->get();
        $mapping = [];

        foreach ($placements as $placement) {
            $copy = MapLayer::query()->create([
                'map_id' => $target->id,
                'layer_id' => $placement->layer_id,
                'parent_id' => null,
                'sort_key' => $placement->sort_key,
                'visible' => $placement->visible,
                'opacity' => $placement->opacity,
                'min_zoom' => $placement->min_zoom,
                'max_zoom' => $placement->max_zoom,

                // The copy gets `edit` where the source owned the layer and
                // `read` otherwise. It does not get `owner`: the layer still
                // belongs to the source map, and ownership is what grants
                // delete.
                'access' => $placement->access === 'owner' ? 'edit' : $placement->access,
                'version' => 1,
            ]);

            $mapping[$placement->id] = $copy->id;
        }

        // Parents are rewritten in a second pass, because a child may be
        // created before its parent and `parent_id` references a placement in
        // the new map, not the old one.
        foreach ($placements as $placement) {
            if ($placement->parent_id === null) {
                continue;
            }

            MapLayer::query()
                ->whereKey($mapping[$placement->id])
                ->update(['parent_id' => $mapping[$placement->parent_id] ?? null]);
        }
    }

    protected function forbidden(string $detail): JsonResponse
    {
        return ProblemResponse::make('command_unauthorized', 403, 'Not permitted', $detail);
    }
}
