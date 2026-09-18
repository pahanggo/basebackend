<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.setZoomRange` — a PLACEMENT command.
 *
 * The third term in effective visibility, which is
 * `own.visible AND all ancestors visible AND zoom within range`. Per placement
 * rather than per layer, because how far out a layer is worth drawing is a
 * property of the map it is in, not of the layer itself.
 */
class LayerSetZoomRange extends Command
{
    public static function op(): string
    {
        return 'layer.setZoomRange';
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

        $min = $this->has('minZoom') ? (int) $this->get('minZoom') : null;
        $max = $this->has('maxZoom') ? (int) $this->get('maxZoom') : null;

        if ($min !== null && $max !== null && $min > $max) {
            throw CommandFailed::invalidProperty('minZoom must not exceed maxZoom.');
        }

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['minZoom', 'maxZoom'], $merged)) {
            return;
        }

        $placement->forceFill([
            'min_zoom' => $min,
            'max_zoom' => $max,
            'version' => $placement->version + 1,
        ])->save();

        $effect = new Effect('placement', $placement->id, $placement->version, ['minZoom', 'maxZoom']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
