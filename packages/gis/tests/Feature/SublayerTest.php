<?php

use Gis\Http\Controllers\Api\FeatureReadController;
use Gis\Http\Encoders\BinaryFeatureEncoder;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Models\MapLayer;
use Gis\Models\MapUser;
use Gis\Support\Classification;
use Gis\Testing\RefreshesGisDatabase;

require_once __DIR__.'/../Helpers.php';

uses(RefreshesGisDatabase::class);

/**
 * A map whose layer carries a classifiable attribute and features spread
 * across three of its values, plus one with none at all.
 *
 * @return array{0: \Gis\Models\Map, 1: Layer, 2: MapLayer}
 */
function classifiableMap(): array
{
    [$map, $layer, $placement] = editableMap();

    $layer->forceFill(['attr_schema' => [
        ['name' => 'gunatanah_kategori', 'type' => 'string'],
        ['name' => 'upi', 'type' => 'string'],
    ]])->save();

    $values = ['Perumahan', 'Perumahan', 'Pertanian', 'Hutan', null];

    foreach ($values as $i => $value) {
        Feature::factory()
            ->at(103.320 + $i * 0.002, 3.800)
            ->create([
                'layer_id' => $layer->id,
                'area_m2' => 10_000.0 - $i,
                'properties' => $value === null ? [] : ['gunatanah_kategori' => $value],
            ]);
    }

    return [$map, $layer->fresh(), $placement];
}

/** A minimal valid classification document. */
function classificationOf(array $values, string $field = 'gunatanah_kategori'): array
{
    return [
        'field' => $field,
        'classes' => array_map(fn (string $v) => [
            'value' => $v, 'label' => $v, 'visible' => true, 'opacity' => 1,
            'style' => ['fill' => '#aabbcc', 'stroke' => '#112233'],
        ], $values),
    ];
}

// ---------------------------------------------------------------- the column

it('stores the classification on the placement, not on the layer', function () {
    // The whole reason the column exists. `layer.setStyle` refuses a locked
    // layer, and every imported layer is locked — so a classification kept in
    // the style would be unreachable for exactly the data worth classifying.
    [$map, $layer, $placement] = classifiableMap();

    $layer->forceFill(['locked' => true])->save();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]])->assertOk();

    expect($placement->fresh()->classification['field'])->toBe('gunatanah_kategori');
    expect($layer->fresh()->style)->not->toHaveKey('field');
});

it('is null until a layer is classified, and null again when cleared', function () {
    [$map, , $placement] = classifiableMap();

    expect($placement->classification)->toBeNull();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Hutan']),
    ]])->assertOk();

    expect($placement->fresh()->classification)->not->toBeNull();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 2,
        'classification' => null,
    ]], envelope: ['seq' => 2])->assertOk();

    expect($placement->fresh()->classification)->toBeNull();
});

// ------------------------------------------------------------ the commands

it('bumps the placement version, never the layer version', function () {
    [$map, $layer, $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]])->assertOk();

    expect($placement->fresh()->version)->toBe(2);
    expect($layer->fresh()->version)->toBe(1);
});

it('refuses a field the layer does not declare', function () {
    [$map, , $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['x'], 'tiada_ini'),
    ]])->assertStatus(422);

    expect($placement->fresh()->classification)->toBeNull();
});

it('refuses two classes claiming the same value', function () {
    // Which class a feature lands in would otherwise depend on array order,
    // and the second row would be unreachable from the tree.
    [$map, , $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Hutan', 'Hutan']),
    ]])->assertStatus(422);
});

it('refuses a colour that is not #rrggbb', function () {
    [$map, , $placement] = classifiableMap();

    $document = classificationOf(['Hutan']);
    $document['classes'][0]['style']['fill'] = 'red; drop table';

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => $document,
    ]])->assertStatus(422);
});

it('patches one class without rewriting the others', function () {
    // The high-frequency path: a checkbox on one sublayer must not collide
    // with a slider on another.
    [$map, , $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan', 'Pertanian', 'Hutan']),
    ]])->assertOk();

    sendCommands($map, [[
        'op' => 'layer.setClassState', 'id' => $placement->id, 'version' => 2,
        'value' => 'Pertanian', 'visible' => false, 'opacity' => 0.25,
        'style' => ['fill' => '#ff0000'],
    ]], envelope: ['seq' => 2])->assertOk();

    $classes = collect($placement->fresh()->classification['classes'])->keyBy('value');

    expect($classes['Pertanian']['visible'])->toBeFalse();
    expect((float) $classes['Pertanian']['opacity'])->toBe(0.25);
    expect($classes['Pertanian']['style']['fill'])->toBe('#ff0000');

    // Untouched, including the colours the patch did not mention.
    expect($classes['Perumahan']['visible'])->toBeTrue();
    expect($classes['Perumahan']['style']['fill'])->toBe('#aabbcc');
    expect((float) $classes['Hutan']['opacity'])->toBe(1.0);
});

it('addresses the other bucket by name', function () {
    [$map, , $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]])->assertOk();

    sendCommands($map, [[
        'op' => 'layer.setClassState', 'id' => $placement->id, 'version' => 2,
        'value' => 'other', 'visible' => false,
    ]], envelope: ['seq' => 2])->assertOk();

    expect($placement->fresh()->classification['other']['visible'])->toBeFalse();
});

it('refuses a class state for a value that is not classified', function () {
    [$map, , $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]])->assertOk();

    sendCommands($map, [[
        'op' => 'layer.setClassState', 'id' => $placement->id, 'version' => 2,
        'value' => 'Pertanian', 'visible' => false,
    ]], envelope: ['seq' => 2])->assertStatus(422);
});

it('conflicts on a stale placement version and reports the classification', function () {
    [$map, , $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]])->assertOk();

    $response = sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Hutan']),
    ]], envelope: ['seq' => 2]);

    $response->assertStatus(409)->assertJsonPath('code', 'version_conflict');

    // The server state travels with the conflict, so the client can resolve
    // without a second round trip.
    expect($response->json('conflicts.0.server'))->toHaveKey('classification');
    expect($response->json('conflicts.0.server.classification.field'))->toBe('gunatanah_kategori');
});

it('lets a read placement classify, because it is this map view, not the layer', function () {
    // Exactly the rule visibility and opacity already follow: a shared layer
    // may be read differently in each map without being changed in any.
    [$map, , $placement] = classifiableMap();

    $placement->forceFill(['access' => 'read'])->save();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]])->assertOk();

    expect($placement->fresh()->classification)->not->toBeNull();
});

it('refuses a viewer', function () {
    [$map, , $placement] = classifiableMap();

    $viewer = reader();
    MapUser::query()->create(['map_id' => $map->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]], $viewer)->assertStatus(403);
});

// ----------------------------------------------------------- the bootstrap

it('carries the classification in the bootstrap', function () {
    [$map, , $placement] = classifiableMap();

    sendCommands($map, [[
        'op' => 'layer.setClassification', 'id' => $placement->id, 'version' => 1,
        'classification' => classificationOf(['Perumahan']),
    ]])->assertOk();

    $response = test()->actingAs(\App\Models\User::findOrFail($map->owner_id))
        ->getJson(route('gis.api.maps.show', ['map' => $map->id]));

    $response->assertOk();

    expect($response->json('layers.0.classification.field'))->toBe('gunatanah_kategori');
    expect($response->json('layers.0.classification.classes.0.value'))->toBe('Perumahan');
});

// ------------------------------------------------------ the values endpoint

it('lists the distinct values of an attribute', function () {
    [, $layer] = classifiableMap();

    $response = test()->actingAs(reader())
        ->getJson(route('gis.api.layers.values', ['layer' => $layer->id, 'field' => 'gunatanah_kategori']));

    $response->assertOk();

    // Sorted naturally, and the feature carrying no value at all is not a
    // value — an absent category is the "other" bucket's business, not a class.
    expect($response->json('values'))->toBe(['Hutan', 'Pertanian', 'Perumahan']);
    expect($response->json('truncated'))->toBeFalse();
});

it('refuses an attribute the layer does not declare', function () {
    [, $layer] = classifiableMap();

    test()->actingAs(reader())
        ->getJson(route('gis.api.layers.values', ['layer' => $layer->id, 'field' => 'properties']))
        ->assertStatus(422);
});

it('reports a field with too many values as truncated rather than listing it', function () {
    // `upi` is a unique key. Grouping the real cadastre by it exhausted a
    // 256 MB memory limit; the cap is what makes this endpoint terminate.
    [, $layer] = classifiableMap();

    config(['gis.read.min_area_px' => 1]);

    foreach (range(1, Classification::MAX_CLASSES + 5) as $i) {
        Feature::factory()->at(103.4 + $i * 0.0005, 3.9)->create([
            'layer_id' => $layer->id,
            'properties' => ['upi' => 'UPI-'.$i],
        ]);
    }

    $response = test()->actingAs(reader())
        ->getJson(route('gis.api.layers.values', ['layer' => $layer->id, 'field' => 'upi']));

    $response->assertOk();

    expect($response->json('truncated'))->toBeTrue();
    expect($response->json('values'))->toBe([]);
});

// -------------------------------------------------------- the feature read

it('sends no class section unless the read asks to classify', function () {
    [, $layer] = classifiableMap();

    $header = classifiedHeader($layer, null);

    expect($header['flags'] & BinaryFeatureEncoder::FLAG_CLASSES)->toBe(0);
    expect($header['classDictLength'])->toBe(0);
});

it('sends one class byte per feature and a dictionary naming them', function () {
    [, $layer] = classifiableMap();

    $body = classifiedBody($layer, 'gunatanah_kategori');
    $header = classifiedHeader($layer, 'gunatanah_kategori');

    expect($header['flags'] & BinaryFeatureEncoder::FLAG_CLASSES)->toBe(BinaryFeatureEncoder::FLAG_CLASSES);

    $dictionary = json_decode(substr($body, $header['classDict'], $header['classDictLength']), true);
    $indices = array_values(unpack('C*', substr($body, $header['classes'], $header['count'])));

    expect($header['count'])->toBe(5);
    expect($indices)->toHaveCount(5);
    expect(array_unique(array_diff($dictionary, ['Perumahan', 'Pertanian', 'Hutan'])))->toBe([]);

    // Every index either names a dictionary entry or is the "other" slot, and
    // the feature with no value at all is the one that takes it.
    $resolved = array_map(
        fn (int $i) => $i === Classification::OTHER ? null : $dictionary[$i],
        $indices,
    );

    sort($resolved);

    expect($resolved)->toBe([null, 'Hutan', 'Pertanian', 'Perumahan', 'Perumahan']);
});

it('names the class in the readable encoding too', function () {
    [, $layer] = classifiableMap();

    $response = test()->actingAs(reader())->get(route('gis.api.layers.features', [
        'layer' => $layer->id, 'bbox' => '103.0,3.7,103.6,3.9', 'zoom' => 18,
        'classify' => 'gunatanah_kategori',
    ]));

    $features = decodeStream($response)['features'];
    // `??` would read an explicit null as absent, and telling those two apart
    // is the point: an unclassified feature carries `_class: null`, it does not
    // omit the key.
    $classes = array_map(
        fn (array $f) => array_key_exists('_class', $f['properties']) ? $f['properties']['_class'] : 'missing',
        $features,
    );

    sort($classes);

    expect($classes)->toBe([null, 'Hutan', 'Pertanian', 'Perumahan', 'Perumahan']);
});

it('refuses to classify the read by an attribute the layer does not declare', function () {
    [, $layer] = classifiableMap();

    test()->actingAs(reader())->get(route('gis.api.layers.features', [
        'layer' => $layer->id, 'bbox' => '103.0,3.7,103.6,3.9', 'zoom' => 18,
        'classify' => 'properties',
    ]))->assertStatus(422);
});

/** The binary body of one classified read. */
function classifiedBody(Layer $layer, ?string $field): string
{
    $response = test()->actingAs(reader())
        ->withHeaders(['Accept' => FeatureReadController::BINARY_TYPE])
        ->get(route('gis.api.layers.features', array_filter([
            'layer' => $layer->id, 'bbox' => '103.0,3.7,103.6,3.9', 'zoom' => 18,
            'classify' => $field,
        ])));

    $response->assertOk();

    $body = $response->streamedContent();
    $length = unpack('V', substr($body, 1, 4))[1];

    return substr($body, 5, $length);
}

/** @return array<string, int> */
function classifiedHeader(Layer $layer, ?string $field): array
{
    $body = classifiedBody($layer, $field);

    return unpack('a4magic/vversion/vflags/Vcount/Vrings/Vvertices/Vproperties', $body)
        + unpack(
            'Vcoords/Vbbox/Vids/Varea/VringStarts/VfeatStarts/Vtypes/Vclasses/VclassDict/VpropertiesAt',
            substr($body, 24, 40),
        )
        + unpack('Vtotal/VcoordExponent/VclassDictLength/Vreserved', substr($body, 64, 16));
}
