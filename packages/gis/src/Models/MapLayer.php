<?php

namespace Gis\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a layer sits in one map: parent, order, visibility, opacity, zoom
 * range and what this map may do to it.
 *
 * `parent_id` references another placement, never a layer.
 *
 * @property int $map_id
 * @property int $layer_id
 * @property int|null $parent_id
 * @property string $sort_key  fractional index
 * @property string $access    owner | edit | read
 * @property int $version
 */
class MapLayer extends GisModel
{
    use HasGisFactory;

    protected $table = 'gis_map_layer';

    protected $fillable = [
        'map_id', 'layer_id', 'parent_id', 'sort_key',
        'visible', 'opacity', 'min_zoom', 'max_zoom', 'access', 'version',
    ];

    protected $casts = [
        'map_id' => 'integer',
        'layer_id' => 'integer',
        'parent_id' => 'integer',
        'visible' => 'boolean',
        'opacity' => 'float',
        'min_zoom' => 'integer',
        'max_zoom' => 'integer',
        'version' => 'integer',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class, 'layer_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
