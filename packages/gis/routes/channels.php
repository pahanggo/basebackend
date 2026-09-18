<?php

use Gis\Models\Map;
use Gis\Support\MapAccess;
use Illuminate\Support\Facades\Broadcast;

/*
 * Who may listen to a map.
 *
 * The same resolution the command endpoint uses, so there is one answer to "may
 * this person see this map" rather than two that can drift. A user who may not
 * open the map may not subscribe to its channel either — otherwise the
 * broadcast becomes a read path around the authorization in section 20.
 */

Broadcast::channel('map.{mapId}', function ($user, int $mapId) {
    if (! $user->can(config('gis.route.permission'))) {
        return false;
    }

    $map = Map::query()->find($mapId);

    return $map !== null && MapAccess::resolve($map, (int) $user->getKey()) !== null;
});
