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
     * The command log is three things at once, which is why it is one table
     * rather than three.
     *
     * It is the **idempotency store**: `(map_id, client_id, seq)` is unique, so
     * a retry after a timeout finds its own earlier row and replays the stored
     * response instead of applying twice (specification section 7).
     *
     * It is the **replay log**: every batch carries the map version it produced,
     * and map versions are monotonic per map, so "bring me forward from 31" is
     * an indexed range scan rather than a re-read of the map.
     *
     * And through `gis_command_effects` it is the **per-field history** the
     * conflict merge reads: which fields of which row changed at which version.
     * Without it, "did the other side touch the field I am touching?" has no
     * answer and every stale command is a blocking conflict (section 16).
     */
    public function up(): void
    {
        Schema::create('gis_command_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('map_id');

            // The map version this batch produced. Deliberately not a second
            // counter: the map version is already bumped once per batch and is
            // already what the client tracks, so reusing it removes a column
            // that could disagree with the one beside it.
            $table->unsignedInteger('map_version');

            $table->string('client_id', 64);
            $table->unsignedBigInteger('seq');

            // Points at the application database; no foreign key (see S0).
            $table->unsignedBigInteger('user_id');

            // The batch as sent and the response as returned. The first is what
            // replay reads; the second is what an idempotent retry returns
            // verbatim, so a retry cannot observe a different answer than the
            // original call did.
            $table->json('commands');
            $table->json('response');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['map_id', 'client_id', 'seq'], 'ux_idempotency');
            $table->unique(['map_id', 'map_version'], 'ux_map_version');
            $table->index('created_at', 'ix_created');
        });

        Schema::create('gis_command_effects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('log_id');
            $table->unsignedBigInteger('map_id');

            // feature | layer | placement | measurement | map
            $table->string('entity', 24);
            $table->unsignedBigInteger('entity_id');

            // The version the row carried AFTER this command. A client holding
            // version 4 asks what changed above 4.
            $table->unsignedInteger('version');

            // The field names this command wrote: `geom`, `name`, `style`, or
            // `properties.status`. Field-level, because that is the granularity
            // the merge works at.
            $table->json('fields');

            $table->index(['entity', 'entity_id', 'version'], 'ix_entity_version');
            $table->index('log_id', 'ix_log');
            $table->index('map_id', 'ix_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gis_command_effects');
        Schema::dropIfExists('gis_command_log');
    }
};
