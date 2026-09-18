<?php

namespace Gis\Models;

use Brick\Geo\Geometry;
use Gis\Casts\GeometryCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved measurement: the same shape as a feature, owned by a map rather than
 * a layer, and never drawn on the feature canvas.
 *
 * @property int $map_id
 * @property string $kind  distance | area | bearing
 * @property Geometry|null $geom
 * @property float $value
 * @property string $unit
 */
class Measurement extends GisModel
{
    use HasGisFactory;

    protected $table = 'gis_measurements';

    protected $fillable = ['map_id', 'kind', 'geom', 'value', 'unit', 'label', 'properties', 'version'];

    protected $casts = [
        'map_id' => 'integer',
        'geom' => GeometryCast::class,
        'value' => 'float',
        'properties' => 'array',
        'version' => 'integer',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'map_id');
    }
}
