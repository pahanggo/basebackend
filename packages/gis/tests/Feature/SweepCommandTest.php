<?php

use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

it('sweeps clean against an empty database, repeatedly', function () {
    $this->artisan('gis:sweep')->assertSuccessful();
    $this->artisan('gis:sweep')->assertSuccessful();
    $this->artisan('gis:sweep', ['--dry-run' => true])->assertSuccessful();
});
