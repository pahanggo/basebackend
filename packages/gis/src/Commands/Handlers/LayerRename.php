<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Layer;

/**
 * `layer.rename` — a LAYER command, so it changes the name in every map showing
 * that layer.
 *
 * This is the pair `layer.setVisible` exists to be contrasted with: one edits
 * what the layer IS, the other where it sits in one map. The API does not paper
 * over the difference, because a command that changes what everybody sees must
 * look different from one that changes only this view (specification section 7).
 */
class LayerRename extends Command
{
    public static function op(): string
    {
        return 'layer.rename';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditLayer($this->resolveId($context));
    }

    public function apply(CommandContext $context): void
    {
        $layer = $this->layer($context);
        $name = trim((string) $this->required('name'));

        if ($name === '') {
            throw CommandFailed::missingField(self::op(), 'name');
        }

        if (! $context->guardVersion('layer', $layer, $this->requiredInt('version'), ['name'], $merged)) {
            return;
        }

        $layer->forceFill(['name' => $name, 'version' => $layer->version + 1])->save();

        $effect = new Effect('layer', $layer->id, $layer->version, ['name']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }

    protected function layer(CommandContext $context): Layer
    {
        $id = $this->resolveId($context);
        $layer = $context->access->layer($id);

        if ($layer === null) {
            throw CommandFailed::notFound('layer', $id);
        }

        return $layer;
    }
}
