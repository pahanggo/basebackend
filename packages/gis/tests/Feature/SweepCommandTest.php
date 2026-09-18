<?php

use Gis\Testing\RefreshesGisDatabase;

uses(RefreshesGisDatabase::class);

it('sweeps clean against an empty database, repeatedly', function () {
    $this->artisan('gis:sweep')->assertSuccessful();
    $this->artisan('gis:sweep')->assertSuccessful();
    $this->artisan('gis:sweep', ['--dry-run' => true])->assertSuccessful();
});

it('purges command log entries past their retention and the effects with them', function () {
    [$map, $layer] = editableMap();

    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 1, 'name' => 'Lot A']])->assertOk();

    expect(Gis\Models\CommandLog::count())->toBe(1);
    expect(Gis\Models\CommandEffect::count())->toBe(1);

    Gis\Models\CommandLog::query()->update([
        'created_at' => now()->subDays((int) config('gis.retention.command_log_days') + 1),
    ]);

    $this->artisan('gis:sweep', ['--dry-run' => true])->assertSuccessful();

    expect(Gis\Models\CommandLog::count())->toBe(1, 'a dry run must not delete');

    $this->artisan('gis:sweep')->assertSuccessful();

    // Effects go with the log: a live effect whose commands are gone would
    // leave the conflict merge reading a history it cannot explain.
    expect(Gis\Models\CommandLog::count())->toBe(0);
    expect(Gis\Models\CommandEffect::count())->toBe(0);
});

it('leaves a log entry inside its retention window alone', function () {
    [$map, $layer] = editableMap();

    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 1, 'name' => 'Lot A']])->assertOk();

    $this->artisan('gis:sweep')->assertSuccessful();

    expect(Gis\Models\CommandLog::count())->toBe(1);
});
