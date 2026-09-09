<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Fresh-migrates the isolated "workflow" sqlite connection (":memory:" in
     * tests, per phpunit.xml). Call from a test that exercises the workflow
     * engine's persistence layer.
     */
    protected function migrateWorkflowDatabase(): void
    {
        // Deliberately not `artisan migrate`: the migration-tracking table
        // lives on the main app's default connection, so a second `migrate`
        // call would see this migration as "already run" and skip it even
        // after dropAllTables() below wipes the actual workflow schema.
        // Instead, drop the workflow connection's tables and re-run its one
        // migration file directly, bypassing tracking entirely.
        Schema::connection('workflow')->dropAllTables();

        (require base_path('packages/workflow/src/database/migrations/2026_09_10_000000_create_workflow_tables.php'))->up();
    }
}
