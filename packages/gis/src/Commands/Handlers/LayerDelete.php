<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.delete` — a soft delete of the LAYER, everywhere it appears.
 *
 * **This is not "remove from this map".** That one drops a single placement and
 * leaves the layer alive wherever else it is placed; this removes it from every
 * map showing it. It is therefore offered only to the map that owns the layer,
 * and the UI warns which other maps are affected before it is sent.
 *
 * Soft, and restorable for 30 days — unlike a feature, which is deleted
 * outright. Losing a layer loses everything under it.
 */
class LayerDelete extends Command
{
    public static function op(): string
    {
        return 'layer.delete';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->role->mayMutateLayers()
            && $context->access->ownsLayer($this->resolveId($context));
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->resolveId($context);
        $layer = $context->access->layer($id);

        if ($layer === null) {
            throw CommandFailed::notFound('layer', $id);
        }

        if (! $context->guardVersion('layer', $layer, $this->requiredInt('version'), ['*'])) {
            return;
        }

        $version = $layer->version + 1;

        // The placements stay. Restoring the layer within the window brings it
        // back into every map that had it, which is what a restore has to mean
        // when the thing restored is shared.
        $layer->delete();

        $context->record(new Effect('layer', $id, $version, ['*']));
    }
}
