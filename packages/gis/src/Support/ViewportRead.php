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
     * @param  array{bbox?: string, zoom?: mixed, minArea?: mixed}  $input
     */
    public static function fromQuery(array $input): self
    {
        $parts = array_map('trim', explode(',', (string) ($input['bbox'] ?? '')));

        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            throw new InvalidArgumentException('bbox is required as minx,miny,maxx,maxy in longitude-latitude.');
        }

        return new self(
            (float) $parts[0],
            (float) $parts[1],
            (float) $parts[2],
            (float) $parts[3],
            (int) ($input['zoom'] ?? 12),
            isset($input['minArea']) && is_numeric($input['minArea']) ? (float) $input['minArea'] : null,
        );
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
     * Below this zoom the pre-simplified column is enough; at and above it the
     * user may be editing, and editing needs the real vertices.
     */
    public function usesSimplifiedGeometry(): bool
    {
        return $this->zoom < (int) config('gis.read.edit_min_zoom');
    }
}
