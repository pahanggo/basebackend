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
     * The NOT NULL and the SRID restriction on `geom` are load-bearing: without
     * both, MySQL creates no spatial index and raises no warning. The test
     * suite asserts the index is actually used rather than trusting it.
     */
    public function up(): void
    {
        Schema::create('gis_features', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('layer_id');

            $table->geometry('geom', srid: 4326);

            // Dropped again in 2026_09_19_000200: it held a pre-simplified
            // copy for use below the editing zoom, and nothing reads it now.
            $table->geometry('geom_simple', srid: 4326)->nullable();

            // Redundant against the spatial index on purpose: MySQL cannot
            // combine a spatial index with other index conditions, and these
            // allow ordinary B-tree filtering, cheap extent aggregation and
            // sorting by size.
            $table->double('minx');
            $table->double('miny');
            $table->double('maxx');
            $table->double('maxy');

            // Written once at ingest, geodesically. Zero for points and lines,
            // which are culled by length or not at all. The whole area-cull
            // design rests on this column being indexed (section 4).
            $table->double('area_m2')->default(0);

            $table->unsignedInteger('vertex_count');
            $table->json('properties');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->spatialIndex('geom', 'sx_geom');
            $table->index('layer_id', 'ix_layer');
            $table->index(['layer_id', 'minx', 'maxx'], 'ix_layer_bbox');
            $table->index(['layer_id', 'area_m2'], 'ix_layer_area');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gis_features');
    }
};
