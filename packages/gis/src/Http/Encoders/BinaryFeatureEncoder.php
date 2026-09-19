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
 *     0    char[4]    magic 'GIS1'
 *     4    uint16     version
 *     6    uint16     flags, bit 0 = an attribute tail follows
 *                            bit 1 = coordinates are quantised (see below)
 *                            bit 2 = a class index and dictionary follow
 *     8    uint32     featureCount
 *     12   uint32     ringCount
 *     16   uint32     vertexCount
 *     20   uint32     propertiesLength
 *     24   uint32[10] section offsets, in the order below
 *     64   uint32     totalLength
 *     68   uint32     coordExponent, 0 when coordinates are float64
 *     72   uint32     classDictLength
 *     76   uint32     reserved
 *     80   float64    coords, interleaved longitude and latitude
 *          uint32     ...or quantised, when bit 1 is set
 *          float64    bbox, 4 per feature
 *          float64    ids
 *          float64    area in square metres
 *          uint32     ringStarts, ringCount + 1 with a sentinel
 *          uint32     featStarts, featureCount + 1 with a sentinel
 *          uint8      types
 *          uint8      classes, one per feature, only when bit 2 is set
 *          char[]     class dictionary, JSON array of strings, with bit 2
 *          char[]     attribute tail, JSON, only when asked for
 *
 * **The class dictionary is per document, and the index is local to it.** A
 * streamed response is a sequence of complete documents, so there is no point
 * at which a dictionary spanning all of them could be written — the header is
 * built before the next chunk has been read. Each frame therefore names the
 * values it actually used and numbers them from zero, and the client maps
 * those strings onto the dictionary it accumulates as it appends.
 *
 * That indirection is not overhead, it is the feature: the client holds raw
 * values rather than resolved classes, so re-colouring, hiding or re-splitting
 * a category costs a repaint and never a re-read.
 *
 * Coordinates are copied out of MySQL's WKB **as bytes**. WKB stores each
 * ordinate as a little-endian double, which is exactly what a `Float64Array`
 * wants, so the coordinate run of every ring is a `substr` rather than a
 * decode-and-re-encode. Nothing in the hot path converts a number.
 *
 * **Quantisation.** With a `coordExponent` the coordinate section is halved:
 * each ordinate becomes a `uint32` holding `round((lng + 180) * 10^e)`, or
 * `round((lat + 90) * 10^e)`. The exponent is `gis.read.coord_exponent`,
 * currently 5 — a resolution of 1e-5 degrees, about 1.1 m, with a worst-case
 * error of half that. Seven is the ceiling: there the widest value, longitude
 * at 180, is 3.6e9, which is the largest that still fits a `uint32`.
 *
 * The bias is what makes it unsigned, and unsigned is what makes it portable:
 * PHP's `pack()` has no signed little-endian code, only the machine's own byte
 * order, and this format is explicitly little-endian everywhere else.
 *
 * The conversion runs **once over the whole accumulated blob** in `encode()`,
 * not per ring. Measured over 1.7 million ordinates: unpack 26 ms, scale 34 ms,
 * repack 12 ms. Doing it ring by ring would pay PHP's call overhead 38,000
 * times for the same arithmetic.
 */
final class BinaryFeatureEncoder
{
    public const MAGIC = 'GIS1';

    /**
     * 2 added quantisation, 3 the class sections. A reader must fail on a
     * version it does not know rather than read a `uint32` coordinate section
     * as float64 or a shifted header as offsets — both of which would produce
     * plausible coordinates rather than an error.
     */
    public const VERSION = 3;

    /** An attribute tail follows the index arrays. */
    public const FLAG_PROPERTIES = 1;

    /** Coordinates are biased `uint32` at `coordExponent`, not float64. */
    public const FLAG_QUANTISED = 2;

    /** A per-feature class index and the dictionary it indexes follow. */
    public const FLAG_CLASSES = 4;

    /** Added before scaling, so every ordinate is non-negative. */
    public const LNG_BIAS = 180;

    public const LAT_BIAS = 90;

    public const HEADER_BYTES = 80;

    public const POINT = 1;

    public const LINE = 2;

    public const POLYGON = 3;

    private string $coords = '';

    /** @var array<int, int> vertex index at which each ring starts */
    private array $ringStarts = [];

    /** @var array<int, int> ring index at which each feature starts */
    private array $featStarts = [];

    private string $types = '';

    /** One `uint8` per feature, indexing `$classDict`. */
    private string $classes = '';

    /** @var array<int, string> the distinct class values this document used */
    private array $classDict = [];

    /** @var array<string, int> value to its position in `$classDict` */
    private array $classLookup = [];

    private bool $classified = false;

    private string $bbox = '';

    private string $ids = '';

    private string $area = '';

    /** @var array<int, mixed> */
    private array $properties = [];

    private int $vertices = 0;

    private int $rings = 0;

    private int $features = 0;

    /**
     * @param  int|null  $coordExponent  decimal places to keep, or null for
     *                                   full float64 precision
     */
    public function __construct(private ?int $coordExponent = null) {}

    /**
     * @param  string  $wkb  geometry as WKB in longitude-latitude order, which
     *                        is what `GeometryCast::selectBinary()` asks for
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bounds  minx, miny, maxx, maxy
     */
    public function add(string $wkb, float $id, float $areaM2, array $bounds, mixed $attributes = null, ?string $class = null): void
    {
        $this->featStarts[] = $this->rings;
        $this->types .= chr($this->walk($wkb, 0));

        $this->ids .= pack('e', $id);
        $this->area .= pack('e', $areaM2);
        $this->bbox .= pack('e4', $bounds[0], $bounds[1], $bounds[2], $bounds[3]);

        if ($attributes !== null) {
            $this->properties[] = $attributes;
        }

        if ($this->classified) {
            $this->classes .= chr($this->classSlot($class));
        }

        $this->features++;
    }

    public function count(): int
    {
        return $this->features;
    }

    /**
     * Emit a class index per feature, indexing a dictionary this document
     * carries.
     *
     * Called before the first `add()`. It is a mode rather than an inference
     * from the values, because a viewport whose features all share one category
     * must still say which, and a classified layer with nothing in view must
     * still produce a document the client reads the same way.
     */
    public function classify(): void
    {
        $this->classified = true;
    }

    /**
     * This value's place in the document's dictionary, adding it if new.
     *
     * Null and the empty string share slot `OTHER`, because both mean the same
     * thing to a reader: a feature the classification does not name. The cap
     * exists because the index is a byte — the dictionary cannot outgrow it,
     * and a field with more than 255 values in one viewport was refused as a
     * classification long before it reached here.
     */
    private function classSlot(?string $value): int
    {
        if ($value === null || $value === '') {
            return \Gis\Support\Classification::OTHER;
        }

        if (isset($this->classLookup[$value])) {
            return $this->classLookup[$value];
        }

        if (count($this->classDict) >= \Gis\Support\Classification::OTHER) {
            return \Gis\Support\Classification::OTHER;
        }

        $this->classDict[] = $value;

        return $this->classLookup[$value] = count($this->classDict) - 1;
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

        $dictionary = $this->classified
            ? json_encode($this->classDict, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : '';

        $coords = $this->coordExponent === null
            ? $this->coords
            : $this->quantise($this->coords, $this->coordExponent);

        $ringStarts = pack('V*', ...$this->ringStarts);
        $featStarts = pack('V*', ...$this->featStarts);

        $offset = self::HEADER_BYTES;
        $coordsOffset = $offset;
        $offset += strlen($coords);
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
        $classesOffset = $offset;
        $offset += strlen($this->classes);

        // The three text sections have no alignment requirement, so they sit
        // last and absorb the padding rather than imposing it.
        $classDictOffset = $offset;
        $offset += strlen($dictionary);
        $propertiesOffset = $offset;
        $total = $offset + strlen($tail);

        $header = self::MAGIC
            .pack('v', self::VERSION)
            .pack('v', ($hasProperties ? self::FLAG_PROPERTIES : 0)
                | ($this->coordExponent === null ? 0 : self::FLAG_QUANTISED)
                | ($this->classified ? self::FLAG_CLASSES : 0))
            .pack('V', $this->features)
            .pack('V', $this->rings)
            .pack('V', $this->vertices)
            .pack('V', strlen($tail))
            .pack('V10',
                $coordsOffset,
                $bboxOffset,
                $idsOffset,
                $areaOffset,
                $ringStartsOffset,
                $featStartsOffset,
                $typesOffset,
                $classesOffset,
                $classDictOffset,
                $propertiesOffset,
            )
            .pack('V', $total)
            .pack('V', $this->coordExponent ?? 0)
            .pack('V', strlen($dictionary))
            .pack('V', 0);

        if (strlen($header) !== self::HEADER_BYTES) {
            throw new RuntimeException('GIS1 header is '.strlen($header).' bytes, expected '.self::HEADER_BYTES.'.');
        }

        return $header
            .$coords
            .$this->bbox
            .$this->ids
            .$this->area
            .$ringStarts
            .$featStarts
            .$this->types
            .$this->classes
            .$dictionary
            .$tail;
    }

    /**
     * Float64 ordinates to biased `uint32`, in one pass over the whole blob.
     *
     * Chunked rather than spread in a single `pack(...$all)` call: the argument
     * list would otherwise be one per ordinate, which for a large viewport is
     * millions of arguments on the stack for no gain.
     *
     * Ordinates alternate longitude, latitude, so the bias alternates with
     * them. SRID 4326 keeps both in range, so the result cannot exceed the
     * 3.6e9 that `uint32` affords.
     */
    private function quantise(string $blob, int $exponent): string
    {
        $scale = 10 ** $exponent;
        $out = '';
        $stride = 8192;                                // ordinates per chunk
        $total = intdiv(strlen($blob), 8);

        for ($start = 0; $start < $total; $start += $stride) {
            $take = min($stride, $total - $start);
            $values = unpack('e'.$take, substr($blob, $start * 8, $take * 8));
            $scaled = [];

            foreach ($values as $i => $value) {
                // `unpack` indexes from 1, and even ordinates are longitude.
                $bias = ($start + $i - 1) % 2 === 0 ? self::LNG_BIAS : self::LAT_BIAS;
                $scaled[] = (int) round(($value + $bias) * $scale);
            }

            $out .= pack('V*', ...$scaled);
        }

        return $out;
    }
}
