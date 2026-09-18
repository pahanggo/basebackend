<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.ungroup` — dissolve a group, reparenting its children to the group's
 * own parent.
 *
 * The group's layer row is deleted with it: a group holds nothing but its
 * children, so an empty one left behind is a node with no meaning that the user
 * then has to remove by hand.
 */
class LayerUngroup extends Command
{
    public static function op(): string
    {
        return 'layer.ungroup';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditPlacement($this->resolveId($context));
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->resolveId($context);
        $placement = $context->access->placementById($id);

        if ($placement === null) {
            throw CommandFailed::notFound('placement', $id);
        }

        $layer = $context->access->layer($placement->layer_id);

        if ($layer?->kind !== 'group') {
            throw CommandFailed::invalidProperty('That node is not a group.');
        }

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['*'])) {
            return;
        }

        $version = $placement->version + 1;

        foreach ($placement->children()->get() as $child) {
            $child->forceFill([
                'parent_id' => $placement->parent_id,
                'version' => $child->version + 1,
            ])->save();

            $context->record(new Effect('placement', $child->id, $child->version, ['parentId']));
        }

        $placement->delete();
        $layer->delete();

        $context->record(new Effect('placement', $id, $version, ['*']));
    }
}
