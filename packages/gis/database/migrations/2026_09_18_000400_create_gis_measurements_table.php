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
     * Saved measurements: the same shape as a feature, but owned by a map
     * rather than a layer, and never drawn on the feature canvas.
     */
    public function up(): void
    {
        Schema::create('gis_measurements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('map_id');

            $table->enum('kind', ['distance', 'area', 'bearing']);
            $table->geometry('geom', srid: 4326);

            // The computed result and the unit system it was taken in, so a
            // saved measurement reads back the way it was written.
            $table->double('value');
            $table->string('unit', 16);

            $table->string('label')->nullable();
            $table->json('properties')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->spatialIndex('geom', 'sx_geom');
            $table->index('map_id', 'ix_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gis_measurements');
    }
};
