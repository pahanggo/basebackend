<?php

namespace Gis\Database\Factories;

use Gis\Models\Layer;
use Gis\Models\Map;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Layer> */
class LayerFactory extends Factory
{
    protected $model = Layer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_map_id' => Map::factory(),
            'name' => fake()->words(2, true),
            'kind' => 'vector',
            'locked' => false,
            'style' => ['stroke' => '#3388ff', 'weight' => 2, 'fill' => '#3388ff', 'fillOpacity' => 0.2],
            'attr_schema' => null,
            'source_config' => null,
            'feature_count' => 0,
            'version' => 1,
        ];
    }

    /** Owned by no map: the imported base data, placeable in any map. */
    public function global(): static
    {
        return $this->state(fn () => ['owner_map_id' => null]);
    }
}
