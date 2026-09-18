<?php

namespace Gis\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person may do in one map.
 *
 * Deliberately a different vocabulary from `MapLayer::$access`, which answers
 * what a map may do to a layer. They compose; neither overrides the other.
 *
 * @property int $map_id
 * @property int $user_id
 * @property string $role
 */
class MapUser extends GisModel
{
    protected $table = 'gis_map_user';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['map_id', 'user_id', 'role'];

    protected $casts = [
        'map_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'map_id');
    }
}
