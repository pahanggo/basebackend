<?php

namespace Gis\Testing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * `RefreshDatabase` only knows about one database.
 *
 * `migrate:fresh` drops the tables on the default connection and then runs
 * every migration — including this package's, which target the GIS connection.
 * Without wiping that one too, the second run finds the GIS tables still there
 * and fails. So wipe it first, and transact both connections per test.
 */
trait RefreshesGisDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as protected parentRefreshTestDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('db:wipe', [
                '--database' => config('gis.connection'),
                '--drop-views' => true,
            ]);
        }

        $this->parentRefreshTestDatabase();
    }

    /** @return array<int, string|null> */
    protected function connectionsToTransact(): array
    {
        return [null, config('gis.connection')];
    }
}
