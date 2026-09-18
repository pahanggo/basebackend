<?php

namespace Gis\Support;

use Gis\Commands\CommandFailed;

/**
 * Feature properties are the most likely way an XSS payload enters this
 * application: they are user-supplied strings that land in popups, labels, the
 * attribute table and the legend.
 *
 * **Sanitising happens on ingest, not at display time** (specification section
 * 20). Display-time escaping is still required and still enforced by the
 * `.html()` grep assertion, but it is the second line: the moment a property
 * value reaches a template someone writes later, or an export, or a job that
 * builds a PDF, the ingest rule is the only one that has held.
 *
 * In v1 the command endpoint is the only way a property value enters. In v2 the
 * import job applies this same class.
 */
class PropertySanitizer
{
    /**
     * Property keys become generated column names when indexed, so they are
     * validated rather than escaped (specification section 20).
     */
    public const KEY_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/';

    /**
     * Keys the import reserves for provenance. They are written by the server
     * and may not be set from a command.
     */
    public const RESERVED_KEYS = ['_src'];

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function clean(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw CommandFailed::invalidProperty("Property name {$key} is not a valid identifier.");
            }

            if (in_array($key, self::RESERVED_KEYS, true)) {
                throw CommandFailed::invalidProperty("Property name {$key} is reserved.");
            }

            $clean[$key] = self::value($value);
        }

        return $clean;
    }

    /**
     * Scalars and nested arrays only. An object graph in a property would have
     * no schema to describe it and no column to index it into.
     */
    protected static function value(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }

        if (! is_string($value)) {
            if (is_scalar($value) || $value === null) {
                return $value;
            }

            throw CommandFailed::invalidProperty('Property values must be scalars or arrays of scalars.');
        }

        // Tags stripped rather than escaped: escaping puts `&lt;b&gt;` in the
        // attribute table, where the user sees markup they did not type. The
        // value is text, and text is what is kept.
        $value = strip_tags($value);

        // C0 and C1 control characters, minus tab, newline and carriage return.
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $value);
    }
}
