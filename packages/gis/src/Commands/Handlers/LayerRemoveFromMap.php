<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.removeFromMap` — drops one placement. The layer survives everywhere
 * else it is placed.
 *
 * The distinction from `layer.delete` is the one users most often get wrong, so
 * the two are different commands with different authorization: removing needs
 * only edit rights on this map, deleting needs to own the layer.
 */
class LayerRemoveFromMap extends Command
{
    public static function op(): string
    {
        return 'layer.removeFromMap';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->role->mayMutateLayers()
            && $context->access->placementById($this->resolveId($context)) !== null;
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->resolveId($context);
        $placement = $context->access->placementById($id);

        if ($placement === null) {
            throw CommandFailed::notFound('placement', $id);
        }

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['*'])) {
            return;
        }

        $version = $placement->version + 1;

        // Children are reparented to this node's parent rather than orphaned: a
        // placement whose parent no longer exists disappears from the tree
        // without being deleted, which is the worst of both.
        $placement->children()->update(['parent_id' => $placement->parent_id]);

        $placement->delete();

        $context->record(new Effect('placement', $id, $version, ['*']));
    }
}
