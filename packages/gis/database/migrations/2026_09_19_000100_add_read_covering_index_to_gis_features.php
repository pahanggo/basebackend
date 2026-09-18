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
     * The index the viewport read was always asking for.
     *
     * The read is `layer_id = ? AND area_m2 >= ? AND <bbox> ORDER BY area_m2
     * DESC LIMIT n`, and neither half of it is selective on its own: measured
     * on the 615,373-feature `Gunatanah` layer at zoom 12, the area threshold
     * admits 37% of the layer and the bounding box 20%, while the two together
     * admit 2.7%. MySQL has no cross-column statistics, so it sees two weak
     * filters, declines both indexes and falls back to a full scan with a
     * filesort.
     *
     * Leading with `(layer_id, area_m2)` means a backward scan returns rows
     * already in the order the read wants — no filesort, so the first row is
     * available immediately instead of after the whole sort, which is what the
     * streamed response needs. Carrying the four bounding-box columns as well
     * means the viewport test is answered inside the index, so a row is only
     * read for a feature that survives. That second part is where the time was:
     * walking 100,001 index entries measured 47 ms, and the same walk fetching
     * each row to test the bounding box measured 610 ms.
     *
     * `ix_layer_area` is a leftmost prefix of this index and so is now dead
     * weight; it is dropped rather than left to be chosen by mistake.
     */
    public function up(): void
    {
        Schema::table('gis_features', function (Blueprint $table) {
            $table->index(
                ['layer_id', 'area_m2', 'minx', 'maxx', 'miny', 'maxy'],
                'ix_layer_read',
            );

            $table->dropIndex('ix_layer_area');
        });
    }

    public function down(): void
    {
        Schema::table('gis_features', function (Blueprint $table) {
            $table->index(['layer_id', 'area_m2'], 'ix_layer_area');

            $table->dropIndex('ix_layer_read');
        });
    }
};
