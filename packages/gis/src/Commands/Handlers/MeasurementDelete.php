<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Measurement;

/** `measurement.delete` — hard, like a feature delete and for the same reason. */
class MeasurementDelete extends Command
{
    public static function op(): string
    {
        return 'measurement.delete';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditMeasurements();
    }

    public function apply(CommandContext $context): void
    {
        $id = $this->resolveId($context);
        $measurement = Measurement::query()->where('map_id', $context->map->id)->find($id);

        if ($measurement === null) {
            throw CommandFailed::notFound('measurement', $id);
        }

        if (! $context->guardVersion('measurement', $measurement, $this->requiredInt('version'), ['*'])) {
            return;
        }

        $version = $measurement->version + 1;

        $measurement->delete();

        $context->record(new Effect('measurement', $id, $version, ['*']));
    }
}
