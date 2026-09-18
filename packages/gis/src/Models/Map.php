<?php

namespace Gis\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property array<string, mixed> $view_state
 * @property int $version
 */
class Map extends GisModel
{
    use HasGisFactory;
    use SoftDeletes;

    protected $table = 'gis_maps';

    protected $fillable = ['owner_id', 'name', 'view_state', 'version'];

    protected $casts = [
        'owner_id' => 'integer',
        'view_state' => 'array',
        'version' => 'integer',
    ];

    /**
     * Where layers sit in this map's tree. A placement is not a layer: the same
     * layer may be placed in many maps, under a different parent in each.
     */
    public function placements(): HasMany
    {
        return $this->hasMany(MapLayer::class, 'map_id');
    }

    /**
     * Layers this map owns outright — the ones drawn in it. Layers shared in
     * from elsewhere are reached through `placements`.
     */
    public function ownedLayers(): HasMany
    {
        return $this->hasMany(Layer::class, 'owner_map_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class, 'map_id');
    }

    /**
     * Per-map access. `user_id` points at the application database, so there is
     * no relationship to `App\Models\User` here.
     */
    public function members(): HasMany
    {
        return $this->hasMany(MapUser::class, 'map_id');
    }
}
