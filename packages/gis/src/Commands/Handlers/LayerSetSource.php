<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;

/**
 * `layer.setSource` — a LAYER command: the tile or WMS URL, or an image path.
 *
 * Whole-object replacement like the style, and for the same reason: a source
 * config half-written is a layer that requests nothing or requests nonsense.
 * Keys are validated rather than the whole document, because what belongs in it
 * differs per `kind` and S5b and S13 are what fill those shapes in.
 */
class LayerSetSource extends Command
{
    public static function op(): string
    {
        return 'layer.setSource';
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

        $source = $this->required('sourceConfig');

        if (! is_array($source)) {
            throw CommandFailed::invalidProperty('sourceConfig must be an object.');
        }

        if (! $context->guardVersion('layer', $layer, $this->requiredInt('version'), ['sourceConfig'], $merged)) {
            return;
        }

        $layer->forceFill(['source_config' => $source, 'version' => $layer->version + 1])->save();

        $effect = new Effect('layer', $layer->id, $layer->version, ['sourceConfig']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
