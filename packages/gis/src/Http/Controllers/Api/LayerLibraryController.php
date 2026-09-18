<?php

namespace Gis\Http\Controllers\Api;

use Gis\Http\ProblemResponse;
use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The layer library: layers this user may place in a map.
 *
 * Two rules do the work, and the second is the one that matters.
 *
 * **What they may see** is every global layer plus every layer owned by a map
 * they may open. Nothing else — the library must not become a discovery channel
 * for maps the user cannot access.
 *
 * **What access they may take it at** is `read` for anything listed, and `edit`
 * only where they already hold edit rights on that layer. Without that, a user
 * with their own map could add the cadastral base as editable and rewrite 1.4
 * million features they were only ever meant to read.
 *
 * The modal offers what the server will grant, and `layer.share` re-checks it
 * regardless: **a disabled control in a modal is not an authorization
 * boundary** (specification section 8).
 */
class LayerLibraryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->cannot('viewAny', Map::class)) {
            return ProblemResponse::make('command_unauthorized', 403, 'Not permitted', 'You may not use the GIS module.');
        }

        $userId = (int) $user->getKey();
        $visibleMapIds = Map::query()->visibleTo($userId)->pluck('id');

        $query = Layer::query()
            ->where(fn ($q) => $q->whereNull('owner_map_id')->orWhereIn('owner_map_id', $visibleMapIds))
            ->where('kind', '!=', 'group');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        $layers = $query->orderBy('name')->paginate(perPage: 25);
        $ids = collect($layers->items())->pluck('id');

        // Which of these are already in the target map. They are listed and
        // marked rather than hidden, so a user searching for something they
        // already added sees why it is not selectable.
        $placedIn = (int) $request->query('excludePlacedIn', 0);

        $placed = $placedIn === 0 ? collect() : MapLayer::query()
            ->where('map_id', $placedIn)
            ->whereIn('layer_id', $ids)
            ->pluck('layer_id');

        // Where the user already holds edit rights: they own the layer's map,
        // or hold an `owner`/`edit` placement of it in some map they may open.
        $editable = MapLayer::query()
            ->whereIn('layer_id', $ids)
            ->whereIn('map_id', $visibleMapIds)
            ->whereIn('access', ['owner', 'edit'])
            ->pluck('layer_id')
            ->unique();

        $ownerMapNames = Map::query()
            ->whereIn('id', collect($layers->items())->pluck('owner_map_id')->filter())
            ->pluck('name', 'id');

        return new JsonResponse([
            'data' => collect($layers->items())->map(fn (Layer $layer) => [
                'id' => $layer->id,
                'name' => $layer->name,
                'kind' => $layer->kind,
                'ownerMapId' => $layer->owner_map_id,
                'ownerMapName' => $layer->owner_map_id === null
                    ? null
                    : $ownerMapNames->get($layer->owner_map_id),
                'featureCount' => $layer->feature_count,
                'updatedAt' => $layer->updated_at?->toIso8601String(),
                'availableAccess' => $editable->contains($layer->id) ? ['read', 'edit'] : ['read'],
                'placedInThisMap' => $placed->contains($layer->id),
            ])->all(),
            'meta' => [
                'page' => $layers->currentPage(),
                'perPage' => $layers->perPage(),
                'total' => $layers->total(),
            ],
        ]);
    }
}
