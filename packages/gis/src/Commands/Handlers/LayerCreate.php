<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Layer;
use Gis\Models\MapLayer;

/**
 * `layer.create` — the layer and its placement together, in one command.
 *
 * They are two tables and two ids, and this is the one moment they are always
 * created as a pair: a layer with no placement is invisible everywhere, and a
 * placement needs something to point at. Both ids come back keyed by `tempId`,
 * because the client drew the layer into its tree optimistically and needs to
 * know which is which — the `layerId` guards a rename, the `placementId` guards
 * a reorder.
 *
 * The new layer is owned by this map, which is what grants the authority to
 * delete it later.
 */
class LayerCreate extends Command
{
    protected const KINDS = ['vector', 'group', 'tile', 'wms', 'image'];

    public static function op(): string
    {
        return 'layer.create';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->role->mayMutateLayers();
    }

    public function apply(CommandContext $context): void
    {
        $kind = (string) $this->required('kind');

        if (! in_array($kind, self::KINDS, true)) {
            throw CommandFailed::invalidProperty('kind must be one of '.implode(', ', self::KINDS).'.');
        }

        $layer = Layer::query()->create([
            'owner_map_id' => $context->map->id,
            'name' => trim((string) $this->required('name')),
            'kind' => $kind,
            'locked' => false,
            // No `fillOpacity`: transparency has one control per object and the
            // layer's opacity is it (specification section 10). It lingered here
            // after the rest were removed in S5b, unread but ready to mislead.
            'style' => (array) $this->get('style', ['stroke' => '#3388ff', 'weight' => 2, 'fill' => '#3388ff']),
            'attr_schema' => $this->get('attrSchema'),
            'source_config' => $this->get('sourceConfig'),
            'feature_count' => 0,
            'version' => 1,
        ]);

        $placement = MapLayer::query()->create([
            'map_id' => $context->map->id,
            'layer_id' => $layer->id,
            'parent_id' => $this->has('parentId') ? (int) $this->get('parentId') : null,
            'sort_key' => (string) $this->required('sortKey'),
            'visible' => true,
            'opacity' => 1,
            'access' => 'owner',
            'version' => 1,
        ]);

        $context->record(new Effect('layer', $layer->id, 1, ['name', 'kind', 'style'], $this->get('tempId')));
        $context->record(new Effect('placement', $placement->id, 1, ['parentId', 'sortKey'], $this->get('tempId').':placement'));
    }
}
