<?php

namespace Gis\Commands\Handlers;

use Gis\Commands\Command;
use Gis\Commands\CommandContext;
use Gis\Commands\CommandFailed;
use Gis\Commands\Effect;
use Gis\Models\Measurement;
use Gis\Support\GeometryInput;

/**
 * `measurement.create` — a row owned by the map rather than by a layer.
 *
 * It is in this session's representative set because it is the one write that
 * is authorised by the map role alone: there is no placement and no layer lock
 * to compose with, so it proves the authorisation path does not assume a layer.
 * S10 gives measurements their units and their labels.
 */
class MeasurementCreate extends Command
{
    protected const KINDS = ['distance', 'area', 'bearing'];

    public static function op(): string
    {
        return 'measurement.create';
    }

    public function authorize(CommandContext $context): bool
    {
        return $context->access->mayEditMeasurements();
    }

    public function apply(CommandContext $context): void
    {
        $kind = (string) $this->required('kind');

        if (! in_array($kind, self::KINDS, true)) {
            throw CommandFailed::invalidProperty('kind must be one of '.implode(', ', self::KINDS).'.');
        }

        $measurement = new Measurement([
            'map_id' => $context->map->id,
            'kind' => $kind,
            'geom' => GeometryInput::parse($this->payload),
            'value' => (float) $this->get('value', 0),
            'unit' => (string) $this->get('unit', 'm'),
            'label' => $this->get('label'),
            'properties' => [],
            'version' => 1,
        ]);

        $measurement->save();

        $context->record(new Effect(
            'measurement',
            $measurement->id,
            1,
            ['geom', 'label'],
            $this->get('tempId'),
        ));
    }
}
