<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function __construct()
    {
        $this->connection = config('gis.connection');
    }

    /**
     * Layers are two tables, not one.
     *
     * `gis_layers` is identity — what the layer IS, wherever it appears.
     * `gis_map_layer` is placement — where it sits in one particular map.
     *
     * The split is what makes a layer shareable between maps: the imported
     * cadastral base exists once with `owner_map_id` NULL and is placed
     * read-only in every map that wants it. Copying a map writes placements,
     * never features (specification section 6).
     */
    public function up(): void
    {
        Schema::create('gis_layers', function (Blueprint $table) {
            $table->bigIncrements('id');

            // NULL = a global layer, owned by no map: the imported base data.
            // A layer created by drawing is owned by the map it was drawn in,
            // which is where the authority to delete it lives.
            $table->unsignedBigInteger('owner_map_id')->nullable();

            $table->string('name');
            $table->enum('kind', ['vector', 'group', 'tile', 'wms', 'image']);
            $table->boolean('locked')->default(false);
            $table->json('style');
            $table->json('attr_schema')->nullable();

            // Tile/WMS URL and API key reference, or image path and corners.
            $table->json('source_config')->nullable();

            // Nullable, so no spatial index here — it is metadata for zooming
            // to a layer, not something queried against.
            $table->geometry('extent', srid: 4326)->nullable();

            $table->unsignedInteger('feature_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_map_id', 'ix_owner');
            $table->index('deleted_at', 'ix_deleted');
        });

        Schema::create('gis_map_layer', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('map_id');
            $table->unsignedBigInteger('layer_id');

            // References another PLACEMENT, not a layer: the tree is per-map
            // structure, so a shared layer can sit under different groups in
            // different maps.
            $table->unsignedBigInteger('parent_id')->nullable();

            // Fractional index (base-62 strings: a0, a0V, a1) so reordering a
            // node writes one row instead of renumbering its siblings.
            $table->string('sort_key', 64);

            $table->boolean('visible')->default(true);
            $table->float('opacity')->default(1);
            $table->tinyInteger('min_zoom')->nullable();
            $table->tinyInteger('max_zoom')->nullable();

            // What this MAP may do to the layer. Composes with the user's role
            // on the map and the layer's locked flag; the narrowest wins.
            $table->enum('access', ['owner', 'edit', 'read'])->default('owner');

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['map_id', 'layer_id'], 'ux_map_layer');
            $table->index(['map_id', 'parent_id', 'sort_key'], 'ix_map_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gis_map_layer');
        Schema::dropIfExists('gis_layers');
    }
};
