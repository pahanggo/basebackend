<?php

namespace Gis\Commands\Handlers;

use Gis\Casts\GeometryCast;
use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Feature;
use Gis\Support\GeometryInput;
use Gis\Support\PropertySanitizer;

/**
 * `feature.update` — and every geometry edit in the application.
 *
 * Moving, inserting or deleting a vertex, transforming a feature and applying a
 * boolean operation are five distinct gestures with five distinct undo entries
 * on the client, and all of them serialise to this one op carrying the
 * resulting geometry. The server has no reason to know which gesture produced
 * it, and five near-identical ops would mean five validation paths
 * (specification section 7).
 *
 * **`properties` is a patch, not a replacement.** Sending `{status: 'active'}`
 * sets that one key; a null value removes it. This is what makes per-field
 * merge possible at all — a whole-object write would clobber a concurrent edit
 * to a different key and there would be nothing to merge.
 */
class FeatureUpdate extends Command
{
    public static function op(): string
    {
        return 'feature.update';
    }

    public function authorize(CommandContext $context): bool
    {
        $feature = $this->feature($context);

        return $context->access->mayEditFeaturesOf($feature->layer_id);
    }

    public function apply(CommandContext $context): void
    {
        $feature = $this->feature($context);
        $fields = $this->fields();

        if ($fields === []) {
            throw CommandFailed::missingField(self::op(), 'geom or properties');
        }

        if (! $context->guardVersion('feature', $feature, $this->requiredInt('version'), $fields, $merged)) {
            return;
        }

        $changes = [];

        if ($this->has('geom')) {
            $geometry = GeometryInput::parse($this->payload);

            $changes = [
                'geom' => $geometry,
                ...GeometryInput::boundingBox($geometry),
                'area_m2' => GeometryCast::geodesicArea($geometry),
                'vertex_count' => GeometryCast::countVertices($geometry),
            ];
        }

        if ($this->has('properties')) {
            $patch = PropertySanitizer::clean((array) $this->get('properties'));
            $properties = $feature->properties ?? [];

            foreach ($patch as $key => $value) {
                if ($value === null) {
                    unset($properties[$key]);

                    continue;
                }

                $properties[$key] = $value;
            }

            $changes['properties'] = $properties;
        }

        $changes['version'] = $feature->version + 1;

        $feature->forceFill($changes)->save();

        $effect = new Effect('feature', $feature->id, $feature->version, $fields);

        $context->record($merged ? $effect->asMerged() : $effect);
    }

    /**
     * The fields this command writes, at the granularity the merge compares.
     *
     * `properties.status` rather than `properties`, because two clients setting
     * two different keys is the case that has to merge rather than conflict.
     *
     * @return array<int, string>
     */
    protected function fields(): array
    {
        $fields = [];

        if ($this->has('geom')) {
            $fields[] = 'geom';
        }

        if ($this->has('properties')) {
            foreach (array_keys((array) $this->get('properties')) as $key) {
                $fields[] = "properties.{$key}";
            }
        }

        return $fields;
    }

    protected function feature(CommandContext $context): Feature
    {
        $id = $this->resolveId($context);
        $feature = Feature::query()->find($id);

        if ($feature === null) {
            throw CommandFailed::notFound('feature', $id);
        }

        return $feature;
    }
}
