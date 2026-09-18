<?php

namespace Gis\Support;

/**
 * Fractional indexing over base 62.
 *
 * A tree node's position is a string, not an integer, so dropping a node
 * between two siblings writes **one row** rather than renumbering everything
 * after it. In a tree where a group may hold hundreds of layers and two people
 * may be dragging at once, a renumber is both a large write and a guaranteed
 * conflict.
 *
 * Keys sort lexicographically: `a0` < `a0V` < `a1`. The client has the same
 * algorithm and computes the key for a drop, because the client is where the
 * drop position is known; this exists for the server-side cases — copying a
 * map, grouping nodes — where there is no client to ask.
 */
class SortKey
{
    private const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** The first key in an empty list. */
    public static function first(): string
    {
        return 'a0';
    }

    /**
     * A key strictly between two others.
     *
     * A null bound is an open end: `between(null, 'a0')` comes before
     * everything, `between('a0', null)` after everything.
     */
    public static function between(?string $before, ?string $after): string
    {
        $before ??= '';
        $after ??= '';

        // Appending is NOT a bisection. Bisecting towards an open end converges
        // on 'z' and then grows a character per insert; incrementing the last
        // digit keeps an ordinary append list at three or four characters
        // forever.
        if ($after === '') {
            return $before === '' ? self::first() : self::increment($before);
        }

        if ($after !== '' && $before !== '' && strcmp($before, $after) >= 0) {
            // Callers pass positions from a tree that may have moved under
            // them. Rather than emit a key that sorts wrongly, fall back to
            // appending, which is always valid if not always where the user
            // aimed.
            $after = '';
        }

        $prefix = '';
        $i = 0;

        // Walk the common prefix; the keys only differ from there on.
        while (true) {
            $b = $i < strlen($before) ? $before[$i] : null;
            $a = $i < strlen($after) ? $after[$i] : null;

            if ($b !== null && $a !== null && $b === $a) {
                $prefix .= $b;
                $i++;

                continue;
            }

            $low = $b === null ? 0 : strpos(self::DIGITS, $b);
            $high = $a === null ? strlen(self::DIGITS) : strpos(self::DIGITS, $a);

            if ($high - $low > 1) {
                return $prefix.self::DIGITS[intdiv($low + $high, 2)];
            }

            // No room at this position: keep the lower key's digit and go one
            // character deeper. This is why keys grow rather than collide.
            $prefix .= $b ?? self::DIGITS[0];
            $i++;
        }
    }

    /** The key after every key in the list. */
    public static function after(?string $last): string
    {
        return $last === null ? self::first() : self::increment($last);
    }

    /**
     * The next key in an append sequence.
     *
     * An odometer with carry: `a0` becomes `a1`, `az` becomes `b0`, and only a
     * key whose every digit is `z` grows a character. That keeps an append-only
     * list at two characters for its first 1,612 layers.
     *
     * Deliberately NOT the smallest key greater than this one — that would be
     * `b` rather than `b0`, which throws away a whole digit of space and grows
     * the key by a character every sixty appends.
     */
    private static function increment(string $key): string
    {
        $chars = str_split($key);

        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $digit = strpos(self::DIGITS, $chars[$i]);

            if ($digit < strlen(self::DIGITS) - 1) {
                $chars[$i] = self::DIGITS[$digit + 1];

                return implode('', $chars);
            }

            $chars[$i] = self::DIGITS[0];
        }

        // Every digit was already at its maximum, so there is nowhere to carry.
        return $key.self::DIGITS[0];
    }
}
