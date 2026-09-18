<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\MapLayer;

/**
 * `layer.setVisible` — a PLACEMENT command. Hiding a layer here hides it in this
 * map and nowhere else.
 *
 * `id` is therefore the placement id, not the layer id, and the version guarded
 * is the placement's. Sending one where the other is meant is a conflict, which
 * is the intended way to find that mistake (specification section 7).
 */
class LayerSetVisible extends Command
{
    public static function op(): string
    {
        return 'layer.setVisible';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditPlacement($this->resolveId($context));
    }

    public function apply(CommandContext $context): void
    {
        $placement = $this->placement($context);

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['visible'], $merged)) {
            return;
        }

        $placement->forceFill([
            'visible' => (bool) $this->required('visible'),
            'version' => $placement->version + 1,
        ])->save();

        $effect = new Effect('placement', $placement->id, $placement->version, ['visible']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }

    protected function placement(CommandContext $context): MapLayer
    {
        $id = $this->resolveId($context);
        $placement = $context->access->placementById($id);

        if ($placement === null) {
            throw CommandFailed::notFound('placement', $id);
        }

        return $placement;
    }
}
