<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.setLocked` — a LAYER command. Blocks editing in every map.
 *
 * The layer stays visible, selectable, queryable and measurable; it simply
 * cannot be written. It is the third term in the effective-permission
 * calculation, and it applies to the layer's owner too — a lock that its owner
 * could edit through would protect nothing.
 */
class LayerSetLocked extends Command
{
    public static function op(): string
    {
        return 'layer.setLocked';
    }

    public function authorize(CommandContext $context): bool
    {
        $id = $this->resolveId($context);

        // Deliberately NOT `mayEditLayer`, which refuses a locked layer — that
        // would make unlocking impossible. The placement's access still has to
        // allow it.
        $placement = $context->access->placement($id);

        return $context->access->role->mayMutateLayers()
            && $placement !== null
            && in_array($placement->access, ['owner', 'edit'], true);
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->resolveId($context);
        $layer = $context->access->layer($id);

        if ($layer === null) {
            throw CommandFailed::notFound('layer', $id);
        }

        if (! $context->guardVersion('layer', $layer, $this->requiredInt('version'), ['locked'], $merged)) {
            return;
        }

        $layer->forceFill([
            'locked' => (bool) $this->required('locked'),
            'version' => $layer->version + 1,
        ])->save();

        $effect = new Effect('layer', $layer->id, $layer->version, ['locked']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
