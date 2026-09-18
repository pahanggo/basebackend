<?php

namespace Gis\Support;

use InvalidArgumentException;

/**
 * One viewport read, validated: a bounding box, a zoom, and the area threshold
 * they imply.
 *
 * The threshold is derived here and never sent by the client. A client that
 * could choose its own threshold could ask for all 164,000 candidates in a
 * zoom-12 viewport, which is the one request the whole design exists to
 * prevent.
 */
final class ViewportRead
{
    /** Ground resolution in metres per pixel at the equator, zoom 0. */
    private const EQUATOR_METRES_PER_PIXEL = 156543.03392;

    public function __construct(
        public readonly float $minx,
        public readonly float $miny,
        public readonly float $maxx,
        public readonly float $maxy,
        public readonly int $zoom,
        public readonly ?float $minAreaOverride = null,

        /**
         * A box the caller already holds every feature for.
         *
         * Anything whose bounding box meets it is left out of the response, so
         * a pan asks only for what it does not have. See `Held`.
         *
         * @var Held|null
         */
        public readonly ?Held $exclude = null,
    ) {
        if ($minx >= $maxx || $miny >= $maxy) {
            throw new InvalidArgumentException('bbox must be minx,miny,maxx,maxy with minx < maxx and miny < maxy.');
        }

        if ($minx < -180 || $maxx > 180 || $miny < -90 || $maxy > 90) {
            throw new InvalidArgumentException('bbox must be longitude-latitude within -180..180, -90..90.');
        }

        if ($zoom < 0 || $zoom > 24) {
            throw new InvalidArgumentException('zoom must be between 0 and 24.');
        }
    }

    /**
     * @param  array{bbox?: string, zoom?: mixed, minArea?: mixed, held?: string}  $input
     */
    public static function fromQuery(array $input): self
    {
        $box = self::box($input['bbox'] ?? '', 'bbox');
        $held = isset($input['held']) && $input['held'] !== ''
            ? self::box($input['held'], 'held')
            : null;

        return new self(
            $box[0],
            $box[1],
            $box[2],
            $box[3],
            (int) ($input['zoom'] ?? 12),
            isset($input['minArea']) && is_numeric($input['minArea']) ? (float) $input['minArea'] : null,
            $held === null ? null : new Held($held[0], $held[1], $held[2], $held[3]),
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function box(string $value, string $name): array
    {
        $parts = array_map('trim', explode(',', $value));

        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            throw new InvalidArgumentException("{$name} is required as minx,miny,maxx,maxy in longitude-latitude.");
        }

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2], (float) $parts[3]];
    }

    /** Latitude at the middle of the viewport, which is where the scale is taken. */
    public function centreLatitude(): float
    {
        return ($this->miny + $this->maxy) / 2;
    }

    public function metresPerPixel(): float
    {
        return self::EQUATOR_METRES_PER_PIXEL * cos(deg2rad($this->centreLatitude())) / (2 ** $this->zoom);
    }

    /**
     * Square metres below which a feature is not worth painting.
     *
     * `minArea=0` suppresses the cull, for export and attribute queries where
     * invisibility is irrelevant. It is never the default and the client does
     * not send it for a viewport read.
     */
    public function areaThreshold(): float
    {
        if ($this->minAreaOverride !== null) {
            return $this->minAreaOverride;
        }

        $metresPerPixel = $this->metresPerPixel();

        return $metresPerPixel ** 2 * (float) config('gis.read.min_area_px');
    }

    /**
     * Below the zoom at which editing becomes possible.
     *
     * The only thing this still decides is whether coordinates may be
     * quantised: above it a vertex can be dragged and sent back, so a read that
     * had rounded it would write the rounding into storage.
     *
     * It used to select `geom_simple` as well. It does not any more — the read
     * returns the stored geometry at every zoom, and nothing in this package
     * changes the shape of a feature on its way to the screen.
     */
    public function belowEditingZoom(): bool
    {
        return $this->zoom < (int) config('gis.read.edit_min_zoom');
    }
}
