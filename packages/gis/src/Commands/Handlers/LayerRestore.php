<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Layer;
use Illuminate\Support\Facades\Auth;

/**
 * `layer.restore` — administrators only, including the owning map's owner.
 *
 * An owner may delete and may not undo it themselves. That is the usual shape
 * for a destructive action with a recovery path, and it keeps the recovery
 * auditable to a small group (specification section 8).
 *
 * The layer being restored need not belong to the map the batch was addressed
 * to: it is named by `id`, because a deleted layer has no live map to speak
 * through.
 */
class LayerRestore extends Command
{
    public static function op(): string
    {
        return 'layer.restore';
    }

    public function authorize(CommandContext $context): bool
    {
        return (bool) Auth::user()?->can(config('gis.route.admin_permission'));
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->requiredInt('id');

        $layer = Layer::withTrashed()->find($id);

        if ($layer === null) {
            throw CommandFailed::notFound('layer', $id);
        }

        if ($layer->deleted_at === null) {
            throw CommandFailed::invalidProperty('That layer is not deleted.');
        }

        $layer->restore();

        $context->record(new Effect('layer', $id, $layer->version, ['*']));
    }
}
