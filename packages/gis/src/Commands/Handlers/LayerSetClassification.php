<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Support\Classification;
use InvalidArgumentException;

/**
 * `layer.setClassification` — a PLACEMENT command: split this layer into
 * sublayers by one of its properties, reconfigure the split, or clear it.
 *
 * **Placement, not layer, and deliberately so.** The specification put
 * classification inside the layer's style until this session. It could not
 * work: `layer.setStyle` goes through `mayEditLayer`, which refuses a locked
 * layer, and the imported layers — the only ones with anything to classify —
 * are all locked. A classification is also a reading of a layer rather than a
 * property of one, so two maps may split the same land use by category and by
 * district without arguing. That makes it the same kind of thing as `visible`,
 * `opacity` and the zoom range, which is why it is authorised the same way.
 *
 * Whole replacement, like `layer.setStyle` and unlike `feature.update`'s
 * property patch: a classification naming a field with no classes, or classes
 * belonging to a different field, paints nothing. The per-class controls in the
 * tree go through `layer.setClassState` instead, which is the high-frequency
 * path and merges per class.
 */
class LayerSetClassification extends Command
{
    public static function op(): string
    {
        return 'layer.setClassification';
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

        if ($layer === null) {
            throw CommandFailed::notFound('layer', $placement->layer_id);
        }

        try {
            $classification = Classification::normalise($this->get('classification'), $layer);
        } catch (InvalidArgumentException $e) {
            throw CommandFailed::invalidProperty($e->getMessage());
        }

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['classification'], $merged)) {
            return;
        }

        $placement->forceFill([
            'classification' => $classification,
            'version' => $placement->version + 1,
        ])->save();

        $effect = new Effect('placement', $placement->id, $placement->version, ['classification']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
