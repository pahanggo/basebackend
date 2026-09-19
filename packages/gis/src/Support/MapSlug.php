<?php

namespace Gis\Support;

use Gis\Models\Map;
use Illuminate\Support\Str;

/**
 * A map's readable name in a URL.
 *
 * One place, because the slug is generated from three: creating a map,
 * renaming one, and the migration that backfilled the column. Three
 * implementations of "make this unique" is three chances to differ, and the
 * one that differs shows up as a duplicate-key error on somebody's save.
 *
 * **Soft-deleted maps still hold their slugs.** A map is restorable for thirty
 * days, so a slug freed by a delete has to stay reserved until `gis:sweep`
 * purges the row — otherwise the restore collides with whatever took the name
 * in the meantime and fails at the one moment the user needs it to work.
 */
final class MapSlug
{
    /** Matches the column, with room for the disambiguating suffix. */
    public const MAX_LENGTH = 160;

    /**
     * @param  int|null  $ignoreId  the map being renamed, which may keep its own slug
     */
    public static function forName(string $name, ?int $ignoreId = null): string
    {
        // A name of only punctuation, or in a script `Str::slug` transliterates
        // away entirely, leaves nothing to put in a URL.
        $base = Str::limit(Str::slug($name), self::MAX_LENGTH - 8, '') ?: 'map';
        $slug = $base;

        for ($suffix = 2; self::taken($slug, $ignoreId); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    protected static function taken(string $slug, ?int $ignoreId): bool
    {
        return Map::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
