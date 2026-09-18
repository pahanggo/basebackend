<?php

namespace Gis\Models;

use Gis\Casts\GeometryCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a layer IS, wherever it appears. Where it sits in a given map is
 * `MapLayer`.
 *
 * @property int $id
 * @property int|null $owner_map_id  NULL for a global layer, owned by no map
 * @property string $name
 * @property string $kind
 * @property bool $locked
 * @property array<string, mixed> $style
 * @property int $version
 */
class Layer extends GisModel
{
    use HasGisFactory;
    use SoftDeletes;

    protected $table = 'gis_layers';

    protected $fillable = [
        'owner_map_id', 'name', 'kind', 'locked', 'style',
        'attr_schema', 'source_config', 'extent', 'feature_count', 'version',
    ];

    protected $casts = [
        'owner_map_id' => 'integer',
        'locked' => 'boolean',
        'style' => 'array',
        'attr_schema' => 'array',
        'source_config' => 'array',
        'extent' => GeometryCast::class,
        'feature_count' => 'integer',
        'version' => 'integer',
    ];

    /** A layer with no owner map is global: placed anywhere, owned nowhere. */
    public function isGlobal(): bool
    {
        return $this->owner_map_id === null;
    }

    public function ownerMap(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'owner_map_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(MapLayer::class, 'layer_id');
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class, 'layer_id');
    }
}
