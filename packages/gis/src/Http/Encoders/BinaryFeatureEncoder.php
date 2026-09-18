<?php

namespace Gis\Http\Encoders;

use RuntimeException;

/**
 * The `GIS1` wire format: the renderer's typed arrays, as bytes.
 *
 * The format exists for one reason — **no parse step**. A 20,000-feature
 * GeoJSON response measured 7.3 MB and 151 ms to parse into typed arrays on the
 * client; the same features here are about a third of the size and are read by
 * pointing `Float64Array` at an offset, which costs nothing.
 *
 * Layout. All integers little-endian, every float section 8-byte aligned so a
 * typed array can view it without copying:
 *
 *     0   char[4]   magic 'GIS1'
 *     4   uint16    version
 *     6   uint16    flags, bit 0 = an attribute tail follows
 *     8   uint32    featureCount
 *     12  uint32    ringCount
 *     16  uint32    vertexCount
 *     20  uint32    propertiesLength
 *     24  uint32[8] section offsets, in the order below
 *     56  uint32    totalLength
 *     60  uint32    reserved
 *     64  float64   coords, interleaved longitude and latitude
 *         float64   bbox, 4 per feature
 *         float64   ids
 *         float64   area in square metres
 *         uint32    ringStarts, ringCount + 1 with a sentinel
 *         uint32    featStarts, featureCount + 1 with a sentinel
 *         uint8     types
 *         char[]    attribute tail, JSON, only when asked for
 *
 * Coordinates are copied out of MySQL's WKB **as bytes**. WKB stores each
 * ordinate as a little-endian double, which is exactly what a `Float64Array`
 * wants, so the coordinate run of every ring is a `substr` rather than a
 * decode-and-re-encode. Nothing in this encoder converts a number.
 */
final class BinaryFeatureEncoder
{
    public const MAGIC = 'GIS1';

    public const VERSION = 1;

    public const HEADER_BYTES = 64;

    public const POINT = 1;

    public const LINE = 2;

    public const POLYGON = 3;

    private string $coords = '';

    /** @var array<int, int> vertex index at which each ring starts */
    private array $ringStarts = [];

    /** @var array<int, int> ring index at which each feature starts */
    private array $featStarts = [];

    private string $types = '';

    private string $bbox = '';

    private string $ids = '';

    private string $area = '';

    /** @var array<int, mixed> */
    private array $properties = [];

    private int $vertices = 0;

    private int $rings = 0;

    private int $features = 0;

    /**
     * @param  string  $wkb  geometry as WKB in longitude-latitude order, which
     *                        is what `GeometryCast::selectBinary()` asks for
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds  minx, miny, maxx, maxy
     */
    public function add(string $wkb, float $id, float $areaM2, array $bounds, mixed $attributes = null): void
    {
        $this->featStarts[] = $this->rings;
        $this->types .= chr($this->walk($wkb, 0));

        $this->ids .= pack('e', $id);
        $this->area .= pack('e', $areaM2);
        $this->bbox .= pack('e4', $bounds[0], $bounds[1], $bounds[2], $bounds[3]);

        if ($attributes !== null) {
            $this->properties[] = $attributes;
        }

        $this->features++;
    }

    public function count(): int
    {
        return $this->features;
    }

    /**
     * Walk one WKB geometry, appending its rings.
     *
     * @return int the geometry type, as this format numbers them
     */
    private function walk(string $wkb, int $offset): int
    {
        $byteOrder = ord($wkb[$offset]);

        if ($byteOrder !== 1) {
            throw new RuntimeException('Expected little-endian WKB from MySQL.');
        }

        $type = unpack('V', substr($wkb, $offset + 1, 4))[1];
        $offset += 5;

        switch ($type) {
            case 1:                                    // Point
                $this->appendRing($wkb, $offset, 1);

                return self::POINT;

            case 2:                                    // LineString
                $this->appendCountedRing($wkb, $offset);

                return self::LINE;

            case 3:                                    // Polygon
                $this->appendPolygon($wkb, $offset);

                return self::POLYGON;

            case 4:                                    // MultiPoint
            case 5:                                    // MultiLineString
            case 6:                                    // MultiPolygon
                $parts = unpack('V', substr($wkb, $offset, 4))[1];
                $offset += 4;

                for ($p = 0; $p < $parts; $p++) {
                    $offset = $this->skipPart($wkb, $offset, $type);
                }

                return $type === 4 ? self::POINT : ($type === 5 ? self::LINE : self::POLYGON);

            default:
                throw new RuntimeException("Unsupported WKB type {$type}.");
        }
    }

    /** One member of a multi-part geometry, returning where it ends. */
    private function skipPart(string $wkb, int $offset, int $parentType): int
    {
        $offset += 5;                                  // the member's own byte order and type

        if ($parentType === 4) {
            $this->appendRing($wkb, $offset, 1);

            return $offset + 16;
        }

        if ($parentType === 5) {
            return $this->appendCountedRing($wkb, $offset);
        }

        return $this->appendPolygon($wkb, $offset);
    }

    private function appendPolygon(string $wkb, int $offset): int
    {
        $ringCount = unpack('V', substr($wkb, $offset, 4))[1];
        $offset += 4;

        for ($r = 0; $r < $ringCount; $r++) {
            $offset = $this->appendCountedRing($wkb, $offset);
        }

        return $offset;
    }

    /** A ring preceded by its point count, returning where it ends. */
    private function appendCountedRing(string $wkb, int $offset): int
    {
        $points = unpack('V', substr($wkb, $offset, 4))[1];
        $offset += 4;

        $this->appendRing($wkb, $offset, $points);

        return $offset + $points * 16;
    }

    /**
     * The one place coordinates move, and they move as bytes.
     */
    private function appendRing(string $wkb, int $offset, int $points): void
    {
        $this->ringStarts[] = $this->vertices;
        $this->coords .= substr($wkb, $offset, $points * 16);
        $this->vertices += $points;
        $this->rings++;
    }

    public function encode(): string
    {
        $this->ringStarts[] = $this->vertices;
        $this->featStarts[] = $this->rings;

        $hasProperties = $this->properties !== [];
        $tail = $hasProperties ? json_encode($this->properties, JSON_THROW_ON_ERROR) : '';

        $ringStarts = pack('V*', ...$this->ringStarts);
        $featStarts = pack('V*', ...$this->featStarts);

        $offset = self::HEADER_BYTES;
        $coordsOffset = $offset;
        $offset += strlen($this->coords);
        $bboxOffset = $offset;
        $offset += strlen($this->bbox);
        $idsOffset = $offset;
        $offset += strlen($this->ids);
        $areaOffset = $offset;
        $offset += strlen($this->area);
        $ringStartsOffset = $offset;
        $offset += strlen($ringStarts);
        $featStartsOffset = $offset;
        $offset += strlen($featStarts);
        $typesOffset = $offset;
        $offset += strlen($this->types);

        // The tail is the only section with no alignment requirement, so it
        // absorbs the padding rather than imposing it.
        $propertiesOffset = $offset;
        $total = $offset + strlen($tail);

        $header = self::MAGIC
            .pack('v', self::VERSION)
            .pack('v', $hasProperties ? 1 : 0)
            .pack('V', $this->features)
            .pack('V', $this->rings)
            .pack('V', $this->vertices)
            .pack('V', strlen($tail))
            .pack('V8',
                $coordsOffset,
                $bboxOffset,
                $idsOffset,
                $areaOffset,
                $ringStartsOffset,
                $featStartsOffset,
                $typesOffset,
                $propertiesOffset,
            )
            .pack('V', $total)
            .pack('V', 0);

        if (strlen($header) !== self::HEADER_BYTES) {
            throw new RuntimeException('GIS1 header is '.strlen($header).' bytes, expected '.self::HEADER_BYTES.'.');
        }

        return $header
            .$this->coords
            .$this->bbox
            .$this->ids
            .$this->area
            .$ringStarts
            .$featStarts
            .$this->types
            .$tail;
    }
}
