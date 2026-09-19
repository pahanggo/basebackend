<?php

namespace Gis\Support;

use Gis\Models\Layer;
use InvalidArgumentException;

/**
 * The classification document: which property splits a layer into sublayers,
 * and how each resulting class paints.
 *
 * It lives on `gis_map_layer.classification` rather than in the layer's style,
 * because a style write refuses a locked layer and every imported layer is
 * locked. The migration that adds the column carries the full argument.
 *
 * This class is the only place the document is validated and the only place a
 * property name becomes a JSON path, so both rules hold everywhere:
 *
 * - **A field must appear in the layer's `attr_schema`.** That is not politeness
 *   about unknown columns. `attr_schema` is a closed list this application
 *   wrote at import, so checking against it bounds what can be asked for — an
 *   arbitrary path would let a caller scan on anything, and `DISTINCT` over an
 *   unbounded field is how a 672,132-value unique key exhausts PHP's memory.
 * - **The path is bound, never interpolated.** `JSON_EXTRACT(properties, ?)`
 *   takes a placeholder perfectly well — unlike the geometry constructor in
 *   `GeometryCast`, whose options argument takes none and which is why that
 *   file interpolates. There is no reason for a string to be spliced into this
 *   SQL and therefore no reason to reason about quoting it.
 */
final class Classification
{
    /**
     * The most classes a layer may be split into.
     *
     * A `uint8` class index on the wire is what makes the per-feature cost one
     * byte, and 255 of those plus the "other" bucket is already far past the
     * 64 distinct paint states section 10 warns at. Nothing legible has 256
     * categories; a field with that many is an identifier, not a category.
     */
    public const MAX_CLASSES = 256;

    /** The class index meaning "not one of the listed values". */
    public const OTHER = 255;

    /**
     * Property names this layer may be classified by.
     *
     * @return array<int, string>
     */
    public static function fieldsOf(Layer $layer): array
    {
        $fields = [];

        foreach ((array) ($layer->attr_schema ?? []) as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;

            if (is_string($name) && self::isFieldName($name)) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * Check a requested field against the layer, or refuse.
     */
    public static function assertField(Layer $layer, mixed $field): string
    {
        if (! is_string($field) || ! in_array($field, self::fieldsOf($layer), true)) {
            throw new InvalidArgumentException('Unknown attribute for this layer.');
        }

        return $field;
    }

    /**
     * The bound JSON path for a field.
     *
     * Travels as a placeholder; see the class docblock.
     */
    public static function path(string $field): string
    {
        return '$.'.$field;
    }

    /**
     * A property name we are willing to turn into a path at all.
     *
     * `attr_schema` is written by the importer and by the schema commands, so
     * this should never reject anything in practice. It exists so that a row
     * edited by hand in the database cannot widen what the path may contain.
     */
    public static function isFieldName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) === 1;
    }

    /**
     * Validate and normalise a whole classification document.
     *
     * Returns null for a cleared classification, which is what unclassifies a
     * layer.
     *
     * @return array<string, mixed>|null
     */
    public static function normalise(mixed $document, Layer $layer): ?array
    {
        if ($document === null) {
            return null;
        }

        if (! is_array($document)) {
            throw new InvalidArgumentException('Classification must be an object or null.');
        }

        $field = self::assertField($layer, $document['field'] ?? null);
        $classes = $document['classes'] ?? [];

        if (! is_array($classes)) {
            throw new InvalidArgumentException('Classification classes must be a list.');
        }

        if (count($classes) > self::MAX_CLASSES) {
            throw new InvalidArgumentException('A layer may be split into at most '.self::MAX_CLASSES.' classes.');
        }

        $normalised = [];
        $seen = [];

        foreach ($classes as $class) {
            if (! is_array($class) || ! array_key_exists('value', $class)) {
                throw new InvalidArgumentException('Each class needs a value.');
            }

            $value = $class['value'];

            if (! is_string($value) && ! is_numeric($value)) {
                throw new InvalidArgumentException('A class value must be a string or a number.');
            }

            $value = (string) $value;

            // Two classes for one value would make the class a feature lands in
            // depend on array order, and the second would be unreachable from
            // the tree because rows are keyed by value.
            if (isset($seen[$value])) {
                throw new InvalidArgumentException('Duplicate class value: '.$value);
            }

            $seen[$value] = true;
            $normalised[] = ['value' => $value] + self::state($class);
        }

        return [
            'field' => $field,
            'classes' => $normalised,
            'other' => self::state(is_array($document['other'] ?? null) ? $document['other'] : []),
        ];
    }

    /**
     * The mutable part of one class: what the tree row controls.
     *
     * Shared by `normalise` and by the patch `layer.setClassState` applies, so
     * a value written by either route has been through the same checks.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function state(array $input, array $onto = []): array
    {
        $state = [
            'label' => $onto['label'] ?? '',
            'visible' => $onto['visible'] ?? true,
            'opacity' => $onto['opacity'] ?? 1.0,
            'style' => $onto['style'] ?? [],
        ];

        if (array_key_exists('label', $input)) {
            $state['label'] = is_string($input['label']) ? mb_substr($input['label'], 0, 255) : '';
        }

        if (array_key_exists('visible', $input)) {
            $state['visible'] = (bool) $input['visible'];
        }

        if (array_key_exists('opacity', $input)) {
            if (! is_numeric($input['opacity'])) {
                throw new InvalidArgumentException('Opacity must be a number.');
            }

            $state['opacity'] = max(0.0, min(1.0, (float) $input['opacity']));
        }

        if (array_key_exists('style', $input)) {
            $state['style'] = self::style($input['style']);
        }

        return $state;
    }

    /**
     * A class's style: the subset of the layer style a sublayer may override.
     *
     * **No `fillOpacity`, and not by omission.** Transparency has one control
     * per object and the class's own `opacity` is it (specification section
     * 10). A key here would multiply that, which is the exact shape that was
     * removed once already.
     *
     * @return array<string, mixed>
     */
    public static function style(mixed $style): array
    {
        if ($style === null || $style === []) {
            return [];
        }

        if (! is_array($style)) {
            throw new InvalidArgumentException('A class style must be an object.');
        }

        $clean = [];

        foreach (['fill', 'stroke'] as $key) {
            if (! array_key_exists($key, $style) || $style[$key] === null) {
                continue;
            }

            if (! is_string($style[$key]) || preg_match('/^#[0-9a-fA-F]{6}$/', $style[$key]) !== 1) {
                throw new InvalidArgumentException("The {$key} colour must be #rrggbb.");
            }

            $clean[$key] = strtolower($style[$key]);
        }

        if (array_key_exists('weight', $style) && $style['weight'] !== null) {
            if (! is_numeric($style['weight'])) {
                throw new InvalidArgumentException('Line width must be a number.');
            }

            $clean['weight'] = max(0.0, min(20.0, (float) $style['weight']));
        }

        return $clean;
    }
}
