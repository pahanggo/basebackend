<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Layer;
use Gis\Models\MapLayer;

/**
 * `layer.group` — wrap selected nodes in a new group.
 *
 * A group is a layer of kind `group` plus its placement, like any other node —
 * which is what lets it be dragged, hidden, faded and reordered by the same
 * commands as everything else rather than needing a parallel vocabulary.
 *
 * It writes the group and reparents the selected placements in one command,
 * because a partially formed group — created but with nothing in it, or nodes
 * reparented to something that does not exist — is a tree the client cannot
 * render.
 */
class LayerGroup extends Command
{
    public static function op(): string
    {
        return 'layer.group';
    }

    public function authorize(CommandContext $context): bool
    {
        if (! $context->access->role->mayMutatePlacements()) {
            return false;
        }

        foreach ($this->placementIds() as $id) {
            if ($context->access->placementById($id) === null) {
                return false;
            }
        }

        return true;
    }

    public function apply(CommandContext $context): void
    {
        $ids = $this->placementIds();

        if ($ids === []) {
            throw CommandFailed::missingField(self::op(), 'placementIds');
        }

        $layer = Layer::query()->create([
            'owner_map_id' => $context->map->id,
            'name' => trim((string) $this->required('name')),
            'kind' => 'group',
            'locked' => false,
            'style' => [],
            'feature_count' => 0,
            'version' => 1,
        ]);

        $group = MapLayer::query()->create([
            'map_id' => $context->map->id,
            'layer_id' => $layer->id,
            'parent_id' => $this->has('parentId') ? (int) $this->get('parentId') : null,
            'sort_key' => (string) $this->required('sortKey'),
            'visible' => true,
            'opacity' => 1,
            'access' => 'owner',
            'version' => 1,
        ]);

        foreach ($ids as $id) {
            $placement = $context->access->placementById($id);

            if ($placement === null) {
                throw CommandFailed::notFound('placement', $id);
            }

            // A node cannot be grouped under itself, and a group cannot be
            // moved into its own descendant. The second is rejected visually
            // before release; this is the server saying so as well.
            if ($this->isAncestorOf($placement->id, $group->id)) {
                throw CommandFailed::invalidProperty('A group cannot contain itself.');
            }

            $placement->forceFill(['parent_id' => $group->id, 'version' => $placement->version + 1])->save();

            $context->record(new Effect('placement', $placement->id, $placement->version, ['parentId']));
        }

        $context->record(new Effect('layer', $layer->id, 1, ['name', 'kind'], $this->get('tempId')));
        $context->record(new Effect('placement', $group->id, 1, ['parentId', 'sortKey'], $this->get('tempId').':placement'));
    }

    /** @return array<int, int> */
    protected function placementIds(): array
    {
        return array_map('intval', (array) $this->get('placementIds', []));
    }

    protected function isAncestorOf(int $candidate, int $node): bool
    {
        $current = MapLayer::query()->find($node);

        // Bounded rather than recursive without a limit: a cycle already in the
        // data would otherwise hang the request rather than reject the command.
        for ($depth = 0; $current !== null && $depth < 64; $depth++) {
            if ($current->parent_id === $candidate) {
                return true;
            }

            $current = $current->parent_id === null ? null : MapLayer::query()->find($current->parent_id);
        }

        return false;
    }
}
