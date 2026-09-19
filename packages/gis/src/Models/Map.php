<?php

namespace Gis\Models;

use Gis\Support\MapSlug;
use Illuminate\Database\Eloquent\Builder;
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

    protected $fillable = ['owner_id', 'name', 'slug', 'view_state', 'version'];

    protected $casts = [
        'owner_id' => 'integer',
        'view_state' => 'array',
        'version' => 'integer',
    ];

    /**
     * Keep the slug in step with the name.
     *
     * Derived rather than stored independently, so a map cannot end up with a
     * URL that says one thing and a title that says another. A rename changes
     * the URL, which is the honest behaviour for a slug derived from a name —
     * the alternative is a slug that slowly stops describing its map.
     *
     * An explicitly supplied slug is left alone: the map copy sets one itself.
     */
    protected static function booted(): void
    {
        static::saving(function (self $map): void {
            if ($map->isDirty('slug') && $map->slug !== null) {
                return;
            }

            if ($map->exists && ! $map->isDirty('name')) {
                return;
            }

            $map->slug = MapSlug::forName((string) $map->name, $map->exists ? $map->getKey() : null);
        });
    }

    /**
     * Maps this user may open: the ones they own, plus the ones they are a
     * member of.
     *
     * `owner_id` is checked as well as the pivot rather than instead of it: a
     * map always has an owner, and requiring the pivot row to exist would make
     * an owner lose their own map to a missed insert.
     */
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('owner_id', $userId)
            ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $userId)));
    }

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
