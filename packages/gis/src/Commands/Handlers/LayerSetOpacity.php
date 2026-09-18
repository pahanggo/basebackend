<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.setOpacity` — a PLACEMENT command, on every layer kind.
 *
 * Vector, raster, image and group alike: opacity multiplies down the tree, so a
 * group at 50% halves everything under it. On an image overlay it is the
 * primary alignment aid — fading a scanned plan until the cadastre shows
 * through it is how the corners get placed (specification section 8).
 */
class LayerSetOpacity extends Command
{
    public static function op(): string
    {
        return 'layer.setOpacity';
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

        $opacity = (float) $this->required('opacity');

        if ($opacity < 0 || $opacity > 1) {
            throw CommandFailed::invalidProperty('opacity must be between 0 and 1.');
        }

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['opacity'], $merged)) {
            return;
        }

        $placement->forceFill(['opacity' => $opacity, 'version' => $placement->version + 1])->save();

        $effect = new Effect('placement', $placement->id, $placement->version, ['opacity']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
