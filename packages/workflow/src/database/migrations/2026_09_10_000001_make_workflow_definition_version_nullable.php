<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A null `version` now means "unpublished draft" — a version number is only
 * assigned when a draft is actually published, so version numbers reflect
 * published history rather than every draft save.
 */
return new class extends Migration
{
    protected $connection = 'workflow';

    public function up(): void
    {
        Schema::connection('workflow')->table('workflow_definition_versions', function (Blueprint $table) {
            $table->unsignedInteger('version')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('workflow_definition_versions', function (Blueprint $table) {
            $table->unsignedInteger('version')->nullable(false)->change();
        });
    }
};
