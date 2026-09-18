<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\MapLayer;

/**
 * `layer.setAccess` — move a placement between `read` and `edit`.
 *
 * **Refused unless the acting map owns the layer.** That refusal is the reason
 * there is no self-escalation path: without it, a user holding a read-only
 * placement of the cadastral base could promote their own placement to `edit`
 * and rewrite 1.4 million rows.
 */
class LayerSetAccess extends Command
{
    public static function op(): string
    {
        return 'layer.setAccess';
    }

    public function authorize(CommandContext $context): bool
    {
        $placement = $this->placement($context);

        if ($placement === null || ! $context->access->role->mayMutateLayers()) {
            return false;
        }

        // The placement being changed usually lives in ANOTHER map — that is
        // the point: the owning map hands out and withdraws edit rights on its
        // layer. So the check is not "may I touch this placement" but "does the
        // map I am acting from own the layer this placement points at".
        return $context->access->ownsLayer($placement->layer_id);
    }

    protected function placement(CommandContext $context): ?MapLayer
    {
        return MapLayer::query()->find($this->resolveId($context));
    }

    public function apply(CommandContext $context): void
    {
        $placement = $this->placement($context);

        if ($placement === null) {
            throw CommandFailed::notFound('placement', $this->resolveId($context));
        }

        $access = (string) $this->required('access');

        if (! in_array($access, ['read', 'edit'], true)) {
            throw CommandFailed::invalidProperty('access must be read or edit.');
        }

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['access'], $merged)) {
            return;
        }

        $placement->forceFill(['access' => $access, 'version' => $placement->version + 1])->save();

        $effect = new Effect('placement', $placement->id, $placement->version, ['access']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
