<?php

namespace Gis\Database\Factories;

use Gis\Casts\GeometryCast;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feature> */
class FeatureFactory extends Factory
{
    protected $model = Feature::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // A small parcel near Kuantan, so the default fixture sits where the
        // real data does and latitude-dependent arithmetic is exercised.
        $lng = fake()->randomFloat(6, 103.30, 103.35);
        $lat = fake()->randomFloat(6, 3.79, 3.83);
        $side = 0.0002;

        return $this->polygon($lng, $lat, $side);
    }

    /** An axis-aligned quadrilateral, the shape a cadastral lot actually is. */
    public function at(float $lng, float $lat, float $side = 0.0002): static
    {
        return $this->state(fn () => $this->polygon($lng, $lat, $side));
    }

    /** @return array<string, mixed> */
    private function polygon(float $lng, float $lat, float $side): array
    {
        $maxx = $lng + $side;
        $maxy = $lat + $side;

        $wkt = sprintf(
            'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
            $lng, $lat, $maxx, $maxy,
        );

        // Roughly, at this latitude: one degree of longitude is ~111 km and
        // one of latitude ~110.6 km. Close enough for a fixture; real areas
        // are computed geodesically at ingest.
        $areaM2 = ($side * 111_000 * cos(deg2rad($lat))) * ($side * 110_600);

        return [
            'layer_id' => Layer::factory(),
            'geom' => GeometryCast::toGeometry($wkt),
            'geom_simple' => null,
            'minx' => $lng,
            'miny' => $lat,
            'maxx' => $maxx,
            'maxy' => $maxy,
            'area_m2' => round($areaM2, 4),
            'vertex_count' => 5,
            'properties' => [],
            'version' => 1,
        ];
    }
}
