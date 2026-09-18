<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Measurement;
use Gis\Support\GeometryInput;

/** `measurement.update` — geometry, label, or both. */
class MeasurementUpdate extends Command
{
    public static function op(): string
    {
        return 'measurement.update';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditMeasurements();
    }

    public function apply(CommandContext $context): void
    {
        $measurement = $this->measurement($context);

        $fields = [];

        if ($this->has('geom')) {
            $fields[] = 'geom';
        }

        if (array_key_exists('label', $this->payload())) {
            $fields[] = 'label';
        }

        if ($fields === []) {
            throw CommandFailed::missingField(self::op(), 'geom or label');
        }

        if (! $context->guardVersion('measurement', $measurement, $this->requiredInt('version'), $fields, $merged)) {
            return;
        }

        $changes = ['version' => $measurement->version + 1];

        if ($this->has('geom')) {
            $changes['geom'] = GeometryInput::parse($this->payload);
            $changes['value'] = (float) $this->get('value', $measurement->value);
        }

        if (in_array('label', $fields, true)) {
            $changes['label'] = $this->get('label');
        }

        $measurement->forceFill($changes)->save();

        $effect = new Effect('measurement', $measurement->id, $measurement->version, $fields);

        $context->record($merged ? $effect->asMerged() : $effect);
    }

    protected function measurement(CommandContext $context): Measurement
    {
        $id = $this->resolveId($context);

        // Scoped to the map in the URL: a measurement id from another map is
        // not addressable through this batch, whatever the acting user's role
        // elsewhere.
        $measurement = Measurement::query()->where('map_id', $context->map->id)->find($id);

        if ($measurement === null) {
            throw CommandFailed::notFound('measurement', $id);
        }

        return $measurement;
    }
}
