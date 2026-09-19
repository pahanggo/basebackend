<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Layer;

/**
 * `layer.setStyle` — the whole style object, replaced.
 *
 * Label configuration lives inside that object, so it is written by this one
 * op rather than by a `setLabels` of its own (specification section 7). S8
 * fills that part out.
 *
 * **Classification does not live here**, though this docblock said it did
 * until S5d. It is placement state, written by `layer.setClassification`,
 * because `authorize()` below refuses a locked layer and every imported layer
 * is locked — so the only layers worth classifying were the only ones this op
 * could not reach. Splitting a shared layer is also this map's reading of it,
 * not a change to the layer (specification section 6).
 *
 * Replacement rather than patch, unlike feature properties: a style is a
 * document the style panel owns whole, and a half-applied style — a graduated
 * ramp with no field — renders nothing.
 */
class LayerSetStyle extends Command
{
    public static function op(): string
    {
        return 'layer.setStyle';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditLayer($this->resolveId($context));
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->resolveId($context);
        $layer = $context->access->layer($id);

        if ($layer === null) {
            throw CommandFailed::notFound('layer', $id);
        }

        $style = $this->required('style');

        if (! is_array($style)) {
            throw CommandFailed::missingField(self::op(), 'style');
        }

        if (! $context->guardVersion('layer', $layer, $this->requiredInt('version'), ['style'], $merged)) {
            return;
        }

        $layer->forceFill(['style' => $style, 'version' => $layer->version + 1])->save();

        $effect = new Effect('layer', $layer->id, $layer->version, ['style']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
