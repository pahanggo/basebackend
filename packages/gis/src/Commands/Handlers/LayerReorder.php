<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\MapLayer;

/**
 * `layer.reorder` — a PLACEMENT command that writes exactly one row.
 *
 * The sort key is a fractional index (base-62 strings: `a0`, `a0V`, `a1`), so
 * dropping a node between two siblings writes that node and renumbers nothing.
 * The client computes the key; the server stores it. A server that renumbered
 * would have to send the whole sibling list back, which is the cost the
 * fractional index exists to remove (specification section 8).
 */
class LayerReorder extends Command
{
    /** Base-62, as produced by the client's fractional indexer. */
    protected const SORT_KEY = '/^[0-9A-Za-z]{1,64}$/';

    public static function op(): string
    {
        return 'layer.reorder';
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

        $sortKey = (string) $this->required('sortKey');

        if (preg_match(self::SORT_KEY, $sortKey) !== 1) {
            throw CommandFailed::invalidProperty('sortKey must be a base-62 fractional index.');
        }

        $parentId = $this->has('parentId') ? (int) $this->get('parentId') : null;

        if ($parentId !== null && $context->access->placementById($parentId) === null) {
            throw CommandFailed::notFound('placement', $parentId);
        }

        // A node cannot be its own parent, and the tree is shallow enough that
        // a deeper cycle check belongs with the tree UI in S5 rather than here.
        if ($parentId === $placement->id) {
            throw CommandFailed::invalidProperty('A placement cannot be its own parent.');
        }

        if (! $context->guardVersion('placement', $placement, $this->requiredInt('version'), ['parentId', 'sortKey'], $merged)) {
            return;
        }

        $placement->forceFill([
            'parent_id' => $parentId,
            'sort_key' => $sortKey,
            'version' => $placement->version + 1,
        ])->save();

        $effect = new Effect('placement', $placement->id, $placement->version, ['parentId', 'sortKey']);

        $context->record($merged ? $effect->asMerged() : $effect);
    }
}
