<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Map;
use Illuminate\Support\Facades\Auth;

/**
 * `map.restore` — administrators only.
 *
 * Sent through any map the user has open, naming the deleted map by `id`: a
 * soft-deleted map cannot receive a command batch of its own, and giving it an
 * endpoint of its own would be a second write path for one operation.
 */
class MapRestore extends Command
{
    public static function op(): string
    {
        return 'map.restore';
    }

    public function authorize(CommandContext $context): bool
    {
        return (bool) Auth::user()?->can(config('gis.route.admin_permission'));
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->requiredInt('id');

        $map = Map::withTrashed()->find($id);

        if ($map === null) {
            throw CommandFailed::notFound('map', $id);
        }

        if ($map->deleted_at === null) {
            throw CommandFailed::invalidProperty('That map is not deleted.');
        }

        $map->restore();

        $context->record(new Effect('map', $id, $map->version, ['*']));
    }
}
