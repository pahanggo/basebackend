<?php

namespace Gis\Commands;

use Gis\Commands\Handlers\FeatureCreate;
use Gis\Commands\Handlers\FeatureDelete;
use Gis\Commands\Handlers\FeatureUpdate;
use Gis\Commands\Handlers\LayerRename;
use Gis\Commands\Handlers\LayerReorder;
use Gis\Commands\Handlers\LayerSetStyle;
use Gis\Commands\Handlers\LayerSetVisible;
use Gis\Commands\Handlers\MeasurementCreate;
use Gis\Commands\Handlers\MeasurementDelete;
use Gis\Commands\Handlers\MeasurementUpdate;

/**
 * op string -> handler class.
 *
 * **This is not a second catalogue.** The catalogue is the table in
 * specification section 7; this is the subset that has a handler yet. Each
 * later session adds its own ops here as it builds them — S5 the tree commands,
 * S6 the rest of feature editing, S8 styling, S9 schema — which is why the map
 * is extendable rather than final.
 *
 * S4 implements a representative spread rather than the whole table: one op per
 * shape the pipeline has to handle. Layer identity against layer placement,
 * geometry against attributes, a create with a `tempId`, a delete, and a
 * map-owned row that is not a feature. Anything the catalogue lists and this
 * does not is not forgotten, it is unbuilt, and it fails with `unknown_op`
 * rather than being quietly ignored.
 */
class CommandRegistry
{
    /** @var array<string, class-string<Command>> */
    protected static array $handlers = [];

    /** @return array<string, class-string<Command>> */
    public static function all(): array
    {
        if (self::$handlers === []) {
            self::$handlers = self::defaults();
        }

        return self::$handlers;
    }

    /**
     * Register or replace a handler.
     *
     * @param  class-string<Command>  $handler
     */
    public static function extend(string $handler): void
    {
        self::all();

        self::$handlers[$handler::op()] = $handler;
    }

    /** @param array<string, mixed> $payload */
    public static function make(array $payload): Command
    {
        $op = $payload['op'] ?? null;

        if (! is_string($op)) {
            throw CommandFailed::missingField('command', 'op');
        }

        $handler = self::all()[$op] ?? null;

        if ($handler === null) {
            throw CommandFailed::unknownOp($op);
        }

        return new $handler($payload);
    }

    /** @return array<string, class-string<Command>> */
    protected static function defaults(): array
    {
        $handlers = [
            FeatureCreate::class,
            FeatureUpdate::class,
            FeatureDelete::class,
            LayerRename::class,
            LayerSetStyle::class,
            LayerSetVisible::class,
            LayerReorder::class,
            MeasurementCreate::class,
            MeasurementUpdate::class,
            MeasurementDelete::class,
        ];

        $map = [];

        foreach ($handlers as $handler) {
            $map[$handler::op()] = $handler;
        }

        return $map;
    }
}
