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
 * `classification` is how this map splits the layer into sublayers, or NULL
 * when it does not. It sits here rather than on the layer because a layer
 * write refuses a locked layer, and every imported layer is locked — see the
 * migration that adds it. Shape:
 *
 * ```
 * {
 *   "field": "gunatanah_kategori",
 *   "classes": [
 *     { "value": "Perumahan", "label": "Perumahan",
 *       "visible": true, "opacity": 1,
 *       "style": { "fill": "#e57373", "stroke": "#b71c1c" } }
 *   ],
 *   "other": { "label": "Lain-lain", "visible": true, "opacity": 1, "style": {} }
 * }
 * ```
 *
 * `other` catches values absent from the list, plus null and empty string. A
 * class's unset style keys fall back to the layer's own style, so a
 * classification carrying no colours at all still paints.
 *
 * @property int $map_id
 * @property int $layer_id
 * @property int|null $parent_id
 * @property string $sort_key  fractional index
 * @property array<string, mixed>|null $classification
 * @property string $access    owner | edit | read
 * @property int $version
 */
class MapLayer extends GisModel
{
    use HasGisFactory;

    protected $table = 'gis_map_layer';

    protected $fillable = [
        'map_id', 'layer_id', 'parent_id', 'sort_key',
        'visible', 'opacity', 'min_zoom', 'max_zoom', 'classification', 'access', 'version',
    ];

    protected $casts = [
        'map_id' => 'integer',
        'layer_id' => 'integer',
        'parent_id' => 'integer',
        'visible' => 'boolean',
        'opacity' => 'float',
        'min_zoom' => 'integer',
        'max_zoom' => 'integer',
        'classification' => 'array',
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
