<?php

namespace Gis\Commands\Handlers;

use Gis\Casts\GeometryCast;
use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Feature;
use Gis\Models\Layer;
use Gis\Support\GeometryInput;
use Gis\Support\PropertySanitizer;

/**
 * `feature.create` — the only way a feature enters the database in v1.
 *
 * Which is why property sanitising lives on this path and not at display time
 * (specification section 20), and why the derived columns are written here
 * rather than by a trigger: `minx`/`maxx`/`miny`/`maxy`, `area_m2` and
 * `vertex_count` are what the read path's index and area cull depend on, and a
 * feature written without them is invisible to every viewport query.
 */
class FeatureCreate extends Command
{
    public static function op(): string
    {
        return 'feature.create';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditFeaturesOf($this->requiredInt('layerId'));
    }

    public function apply(CommandContext $context): void
    {
        $layerId = $this->requiredInt('layerId');
        $layer = $context->access->layer($layerId);

        if ($layer === null) {
            throw CommandFailed::notFound('layer', $layerId);
        }

        $geometry = GeometryInput::parse($this->payload);
        $properties = PropertySanitizer::clean((array) $this->get('properties', []));

        $feature = new Feature([
            'layer_id' => $layerId,
            'geom' => $geometry,
            ...GeometryInput::boundingBox($geometry),
            'area_m2' => GeometryCast::geodesicArea($geometry),
            'vertex_count' => GeometryCast::countVertices($geometry),
            'properties' => $properties,
            'version' => 1,
        ]);

        $feature->save();

        // The layer's own count, kept in step rather than recomputed: a
        // COUNT(*) over 672,000 rows to answer "how many features" is what the
        // column exists to avoid.
        Layer::query()->whereKey($layerId)->increment('feature_count');

        $context->record(new Effect(
            'feature',
            $feature->id,
            1,
            ['geom', ...array_map(fn (string $k) => "properties.{$k}", array_keys($properties))],
            $this->get('tempId'),
        ));
    }
}
