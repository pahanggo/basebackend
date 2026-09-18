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
     * The last of the level-of-detail geometry.
     *
     * `geom_simple` held a pre-simplified copy of each large feature for use
     * below the editing zoom. Nothing reads it now: the read returns stored
     * geometry at every zoom, and the area cull decides what is drawn by
     * dropping whole features rather than reshaping them.
     *
     * The column was derived entirely from `geom`, so nothing original is lost
     * with it — but `down()` can only restore the column, not its contents,
     * because the code that computed them has gone too.
     */
    public function up(): void
    {
        Schema::table('gis_features', function (Blueprint $table) {
            $table->dropColumn('geom_simple');
        });
    }

    public function down(): void
    {
        Schema::table('gis_features', function (Blueprint $table) {
            $table->geometry('geom_simple', srid: 4326)->nullable();
        });
    }
};
