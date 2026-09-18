<?php

use App\Models\User;
use Gis\Events\MapCommandsApplied;
use Gis\Models\CommandLog;
use Gis\Models\Feature;
use Gis\Testing\RefreshesGisDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshesGisDatabase::class);

/** Every batch bumps the map version, so the version IS the replay sequence. */
function replayFrom(int $mapId, int $since, ?User $user = null): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user ?? User::query()->firstOrFail())
        ->getJson(route('gis.api.maps.commands.replay', ['map' => $mapId, 'since' => $since]));
}

it('brings a client forward by replaying only what it missed', function () {
    [$map, $layer] = editableMap();
    $owner = User::query()->findOrFail($map->owner_id);

    $feature = Feature::factory()->create(['layer_id' => $layer->id]);

    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 1, 'name' => 'Lot A']])->assertOk();
    sendCommands($map, [['op' => 'feature.update', 'id' => $feature->id, 'version' => 1, 'properties' => ['status' => 'x']]], envelope: ['seq' => 2])->assertOk();
    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 2, 'name' => 'Lot B']], envelope: ['seq' => 3])->assertOk();

    // A client that last saw version 2 has missed two batches, not three.
    $response = replayFrom($map->id, 2, $owner);

    $response->assertOk()
        ->assertJsonPath('canReplay', true)
        ->assertJsonPath('mapVersion', 4)
        ->assertJsonCount(2, 'batches');

    expect($response->json('batches.0.mapVersion'))->toBe(3);
    expect($response->json('batches.0.commands.0.op'))->toBe('feature.update');
    expect($response->json('batches.1.commands.0.op'))->toBe('layer.rename');
});

it('returns nothing to a client that is already current', function () {
    [$map, $layer] = editableMap();
    $owner = User::query()->findOrFail($map->owner_id);

    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 1, 'name' => 'Lot A']])->assertOk();

    replayFrom($map->id, 2, $owner)
        ->assertOk()
        ->assertJsonPath('canReplay', true)
        ->assertJsonCount(0, 'batches');
});

it('says so rather than replaying a history it no longer holds', function () {
    // Honesty is the point. A partial replay would leave the client believing
    // it is current when it is not, which is worse than the re-read it saves.
    [$map, $layer] = editableMap();
    $owner = User::query()->findOrFail($map->owner_id);

    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 1, 'name' => 'Lot A']])->assertOk();
    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 2, 'name' => 'Lot B']], envelope: ['seq' => 2])->assertOk();

    // The sweep has taken the earliest batch.
    CommandLog::query()->where('map_version', 2)->delete();

    replayFrom($map->id, 1, $owner)
        ->assertOk()
        ->assertJsonPath('canReplay', false)
        ->assertJsonPath('reason', 'log_incomplete');
});

it('refuses the log to someone who may not open the map', function () {
    [$map] = editableMap();

    replayFrom($map->id, 0, reader())
        ->assertStatus(403)
        ->assertJsonPath('code', 'command_unauthorized');
});

it('broadcasts the applied batch on the map channel, carrying the sender', function () {
    // The originating clientId travels with the event so the sender can ignore
    // its own echo. Without it every editor applies its own work twice.
    Event::fake([MapCommandsApplied::class]);

    [$map, $layer] = editableMap();

    sendCommands($map, [['op' => 'layer.rename', 'id' => $layer->id, 'version' => 1, 'name' => 'Lot A']])->assertOk();

    Event::assertDispatched(MapCommandsApplied::class, function (MapCommandsApplied $event) use ($map) {
        expect($event->broadcastOn()->name)->toBe("private-map.{$map->id}");
        expect($event->broadcastWith()['clientId'])->toBe('a3f9c2');
        expect($event->broadcastWith()['mapVersion'])->toBe(2);

        return true;
    });
});

it('does not broadcast a batch that was refused', function () {
    Event::fake([MapCommandsApplied::class]);

    [$map, $layer] = editableMap();

    sendCommands($map, [['op' => 'feature.create', 'tempId' => 't', 'layerId' => $layer->id, 'geom' => 'rubbish']])
        ->assertStatus(422);

    Event::assertNotDispatched(MapCommandsApplied::class);
});
