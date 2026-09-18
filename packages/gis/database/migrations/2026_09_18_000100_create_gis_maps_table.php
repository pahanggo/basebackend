<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function __construct()
    {
        // The package keeps its schema in its own database; the migrator swaps
        // the default connection for the duration of up()/down().
        $this->connection = config('gis.connection');
    }

    public function up(): void
    {
        Schema::create('gis_maps', function (Blueprint $table) {
            $table->bigIncrements('id');

            // No foreign key: `users` lives in another database. The
            // application layer is what keeps this honest.
            $table->unsignedBigInteger('owner_id');

            $table->string('name');
            $table->json('view_state');            // centre, zoom, basemap
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();                 // restorable for 30 days, purged by gis:sweep

            $table->index('owner_id', 'ix_owner');
            $table->index('deleted_at', 'ix_deleted');
        });

        Schema::create('gis_map_user', function (Blueprint $table) {
            $table->unsignedBigInteger('map_id');
            $table->unsignedBigInteger('user_id');

            // What this person may do in this map. Deliberately a different
            // vocabulary from gis_map_layer.access, which is what this map may
            // do to a layer (specification section 20).
            $table->enum('role', ['owner', 'editor', 'contributor', 'viewer']);
            $table->timestamps();

            $table->primary(['map_id', 'user_id']);
            $table->index('user_id', 'ix_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gis_map_user');
        Schema::dropIfExists('gis_maps');
    }
};
