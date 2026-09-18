<?php

namespace Gis\Database\Factories;

use Gis\Models\Layer;
use Gis\Models\Map;
use Gis\Models\MapLayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MapLayer> */
class MapLayerFactory extends Factory
{
    protected $model = MapLayer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'map_id' => Map::factory(),
            'layer_id' => Layer::factory(),
            'parent_id' => null,
            'sort_key' => 'a0',
            'visible' => true,
            'opacity' => 1,
            'access' => 'owner',
            'version' => 1,
        ];
    }

    /** A layer shared in from elsewhere, which this map may not edit. */
    public function readOnly(): static
    {
        return $this->state(fn () => ['access' => 'read']);
    }
}
