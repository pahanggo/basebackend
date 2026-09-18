<?php

namespace Gis\Database\Factories;

use Gis\Models\Map;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Map> */
class MapFactory extends Factory
{
    protected $model = Map::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_id' => 1,
            'name' => fake()->words(2, true),
            'view_state' => [
                'center' => config('gis.default_view.center'),
                'zoom' => config('gis.default_view.zoom'),
                'basemap' => 'default',
            ],
            'version' => 1,
        ];
    }
}
