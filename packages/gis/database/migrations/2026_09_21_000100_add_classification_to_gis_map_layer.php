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
     * How this map splits a layer into sublayers.
     *
     * **On the placement, not on the layer, and that is the whole point.**
     * `gis_layers.style` would have been the obvious home — the specification
     * said so until this session — but a style write goes through
     * `MapAccess::mayEditLayer`, which refuses a locked layer. Every layer
     * worth classifying is locked: the nine imported PLANMalaysia layers are
     * global and locked precisely because 4.3 million features nobody owns
     * must not be edited by whoever opens them. Putting classification on the
     * layer would have made the feature unreachable for all of its data.
     *
     * It belongs here for a second reason as well. A classification is a
     * thematic reading of a shared layer — the same land use coloured by
     * category in a planning map and by district in an administrative one —
     * which is what `visible`, `opacity` and the zoom range already are: this
     * map's view of a layer, not a change to the layer.
     *
     * NULL means unclassified, which is every existing row and every layer
     * that never gets one. The shape is documented on `MapLayer`.
     */
    public function up(): void
    {
        Schema::table('gis_map_layer', function (Blueprint $table) {
            $table->json('classification')->nullable()->after('max_zoom');
        });
    }

    public function down(): void
    {
        Schema::table('gis_map_layer', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }
};
