<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Feature;
use Gis\Models\Layer;

/**
 * `feature.delete` — a hard delete, deliberately.
 *
 * Maps and layers are soft-deleted because losing one loses everything under
 * it; a feature is a single row a user drew and can redraw, and the undo stack
 * already holds its inverse. Soft-deleting 1.4 million cadastral rows would
 * also put a `deleted_at IS NULL` predicate on the hot read path for a case
 * that does not happen (specification section 6).
 */
class FeatureDelete extends Command
{
    public static function op(): string
    {
        return 'feature.delete';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditFeaturesOf($this->feature($context)->layer_id);
    }

    public function apply(CommandContext $context): void
    {
        $feature = $this->feature($context);

        // '*' rather than a field list: a delete touches the whole row, so
        // nothing merges past it. A concurrent edit to a deleted feature is a
        // conflict the user has to see.
        if (! $context->guardVersion('feature', $feature, $this->requiredInt('version'), ['*'])) {
            return;
        }

        $version = $feature->version + 1;
        $layerId = $feature->layer_id;

        $feature->delete();

        Layer::query()->whereKey($layerId)->where('feature_count', '>', 0)->decrement('feature_count');

        $context->record(new Effect('feature', $this->resolveId($context), $version, ['*']));
    }

    protected function feature(CommandContext $context): Feature
    {
        $id = $this->resolveId($context);
        $feature = Feature::query()->find($id);

        if ($feature === null) {
            throw CommandFailed::notFound('feature', $id);
        }

        return $feature;
    }
}
