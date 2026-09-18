/**
 * Fractional indexing, base 62 — the client half of `Gis\Support\SortKey`.
 *
 * A node's position in the tree is a string, so dropping it between two
 * siblings writes **one row** instead of renumbering everything after it. That
 * matters twice over: a renumber of a large group is a big write, and it is a
 * guaranteed conflict the moment two people drag at once.
 *
 * The client owns this because the client is where a drop position is known.
 * The server has the same algorithm for the cases with no client to ask —
 * copying a map, grouping nodes — and the two must agree, so they are tested
 * against the same cases.
 */

const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

export function firstKey() {
    return 'a0';
}

/**
 * A key that sorts strictly between two others.
 *
 * A null bound is an open end: `between(null, 'a0')` comes before everything,
 * `between('a0', null)` after everything.
 */
export function between(before, after) {
    let low = before || '';
    let high = after || '';

    // Appending is NOT a bisection. Bisecting towards an open end converges on
    // 'z' and then grows a character per insert — 500 appends produced an
    // 84-character key. Incrementing the last digit instead keeps an ordinary
    // append list at three or four characters forever.
    if (high === '') {
        return low === '' ? firstKey() : increment(low);
    }

    if (low && high && low >= high) {
        // The tree may have moved under the caller. Emitting a key that sorts
        // wrongly is worse than appending, which is always valid if not always
        // where the user aimed.
        high = '';
    }

    let prefix = '';
    let i = 0;

    for (;;) {
        const b = i < low.length ? low[i] : null;
        const a = i < high.length ? high[i] : null;

        if (b !== null && a !== null && b === a) {
            prefix += b;
            i += 1;

            continue;
        }

        const lowDigit = b === null ? 0 : DIGITS.indexOf(b);
        const highDigit = a === null ? DIGITS.length : DIGITS.indexOf(a);

        if (highDigit - lowDigit > 1) {
            return prefix + DIGITS[Math.floor((lowDigit + highDigit) / 2)];
        }

        // No room at this position: keep the lower key's digit and go one
        // character deeper. This is why keys grow rather than collide.
        prefix += b === null ? DIGITS[0] : b;
        i += 1;
    }
}

export function afterKey(last) {
    return last === null || last === undefined ? firstKey() : increment(last);
}

/**
 * The next key in an append sequence.
 *
 * An odometer with carry: `a0` becomes `a1`, `az` becomes `b0`, and only a key
 * whose every digit is `z` grows a character. That keeps an append-only list at
 * two characters for its first 1,612 layers.
 *
 * It is deliberately NOT the smallest key greater than this one — that would be
 * `b` rather than `b0`, which reads nicer and then throws away a whole digit of
 * space, growing the key by a character every sixty appends. Appending wants
 * room, not minimality.
 */
function increment(key) {
    const chars = key.split('');

    for (let i = chars.length - 1; i >= 0; i -= 1) {
        const digit = DIGITS.indexOf(chars[i]);

        if (digit < DIGITS.length - 1) {
            chars[i] = DIGITS[digit + 1];

            return chars.join('');
        }

        chars[i] = DIGITS[0];
    }

    // Every digit was already at its maximum, so there is nowhere to carry to.
    return key + DIGITS[0];
}
