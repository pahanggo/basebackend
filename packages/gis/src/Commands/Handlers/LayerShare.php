<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Gis\Support\MapAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;

/**
 * `layer.share` — place an existing layer in a map. **Copies nothing.**
 *
 * This is what makes a statewide cadastral base workable: "Lot" and "Gunatanah"
 * hold 1.4 million features between them, imported once and placed read-only in
 * every map that needs them. Placing one writes a single pivot row.
 *
 * **The access check here is the authorization boundary, not the modal.** The
 * library offers the levels the server will grant; this re-checks them
 * regardless, because a disabled control in a dialog stops nobody who can issue
 * an HTTP request (specification section 8).
 */
class LayerShare extends Command
{
    public static function op(): string
    {
        return 'layer.share';
    }

    public function authorize(CommandContext $context): bool
    {
        $userId = (int) Auth::id();
        $target = $this->targetMap();

        if ($target === null) {
            return false;
        }

        // Two separate questions: may they add layers to the target map, and
        // may they take THIS layer at THIS level.
        $targetAccess = MapAccess::resolve($target, $userId);

        if ($targetAccess === null || ! $targetAccess->role->mayMutateLayers()) {
            return false;
        }

        return MapAccess::mayTakeLayerAt($userId, $this->requiredInt('layerId'), $this->access());
    }

    public function apply(CommandContext $context): void
    {
        $target = $this->targetMap();

        if ($target === null) {
            throw CommandFailed::notFound('map', (int) $this->get('mapId', 0));
        }

        try {
            $placement = MapLayer::query()->create([
                'map_id' => $target->id,
                'layer_id' => $this->requiredInt('layerId'),
                'parent_id' => $this->has('parentId') ? (int) $this->get('parentId') : null,
                'sort_key' => (string) $this->required('sortKey'),
                'visible' => true,
                'opacity' => 1,
                'access' => $this->access(),
                'version' => 1,
            ]);
        } catch (QueryException $e) {
            // `(map_id, layer_id)` is unique. Placing a layer twice is
            // meaningless, and the constraint is the boundary — the modal only
            // marks it.
            throw CommandFailed::invalidProperty('That layer is already placed in this map.');
        }

        $context->record(new Effect('placement', $placement->id, 1, ['parentId', 'sortKey', 'access'], $this->get('tempId')));
    }

    protected function access(): string
    {
        $access = (string) $this->get('access', 'read');

        return in_array($access, ['read', 'edit'], true) ? $access : 'read';
    }

    protected function targetMap(): ?Map
    {
        return Map::query()->find((int) $this->get('mapId', 0));
    }
}
