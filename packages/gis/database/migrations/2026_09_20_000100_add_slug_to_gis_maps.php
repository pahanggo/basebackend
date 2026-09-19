<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('gis.connection');
    }

    /**
     * A stable, readable name for a map in a URL.
     *
     * Nullable at first and unique only once every row has one: adding a
     * `NOT NULL UNIQUE` column to a populated table is a failed migration, and
     * backfilling before the constraint exists is the only order that works.
     *
     * **The uniqueness is on `slug` alone, not on `(slug, deleted_at)`.** A map
     * is soft-deleted for thirty days and restorable, so a slug freed by a
     * delete must stay reserved until the sweep purges the row — otherwise
     * restoring a map would collide with whatever took its name in the
     * meantime, and the restore would fail at the worst possible moment.
     */
    public function up(): void
    {
        Schema::table('gis_maps', function (Blueprint $table) {
            $table->string('slug', 160)->nullable()->after('name');
        });

        $taken = [];

        foreach (DB::connection($this->connection)->table('gis_maps')->select('id', 'name')->get() as $map) {
            $slug = self::uniqueSlug($map->name, $taken);
            $taken[$slug] = true;

            DB::connection($this->connection)
                ->table('gis_maps')
                ->where('id', $map->id)
                ->update(['slug' => $slug]);
        }

        Schema::table('gis_maps', function (Blueprint $table) {
            $table->string('slug', 160)->nullable(false)->change();
            $table->unique('slug', 'ux_slug');
        });
    }

    public function down(): void
    {
        Schema::table('gis_maps', function (Blueprint $table) {
            $table->dropUnique('ux_slug');
            $table->dropColumn('slug');
        });
    }

    /**
     * @param  array<string, true>  $taken
     */
    protected static function uniqueSlug(string $name, array $taken): string
    {
        // A name of only punctuation, or of a script `Str::slug` transliterates
        // to nothing, would otherwise produce an empty slug — and then a second
        // empty one, which the unique index refuses.
        $base = Str::slug($name) ?: 'map';
        $slug = $base;

        for ($suffix = 2; isset($taken[$slug]); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
};
