<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
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
        // call would see these migrations as "already run" and skip them even
        // after dropAllTables() below wipes the actual workflow schema.
        // Instead, drop the workflow connection's tables and re-run every
        // migration file directly, in filename order, bypassing tracking.
        Schema::connection('workflow')->dropAllTables();

        $migrationsPath = base_path('packages/workflow/src/database/migrations');
        $files = collect(File::files($migrationsPath))->sortBy(fn ($file) => $file->getFilename());
        foreach ($files as $file) {
            (require $file->getPathname())->up();
        }
    }

    /**
     * Fresh-migrates the isolated "purchase_request_demo" sqlite connection
     * (":memory:" in tests, per phpunit.xml) — same rationale as
     * migrateWorkflowDatabase(). Call from a test that exercises the
     * Purchase Request demo package.
     */
    protected function migratePurchaseRequestDemoDatabase(): void
    {
        Schema::connection('purchase_request_demo')->dropAllTables();

        $migrationsPath = base_path('packages/workflow-demo-purchase-request/src/Database/migrations');
        $files = collect(File::files($migrationsPath))->sortBy(fn ($file) => $file->getFilename());
        foreach ($files as $file) {
            (require $file->getPathname())->up();
        }
    }
}
