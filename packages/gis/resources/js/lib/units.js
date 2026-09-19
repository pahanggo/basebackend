/**
 * Turning metres and square metres into what the reader actually uses.
 *
 * **One quantity can be SI while another is imperial**, because that is how
 * people work: a Malaysian survey office measures distance in metres and land
 * in hectares and acres in the same breath, and a global toggle that forces
 * both would be wrong half the time. So there is a default system and a
 * per-quantity override, stored in `ui.units` (specification §11).
 *
 * Everything in this package is metres internally, always. Conversion happens
 * at the edge, on the way to a label, and never on the way into storage — a
 * number that has been through a unit preference is a number whose meaning
 * depends on who was looking at it.
 */

/** The quantities that can be overridden independently. */
export const QUANTITIES = ['distance', 'area'];

/**
 * Units per quantity and system, largest last.
 *
 * `at` is the value in base units above which this unit is the right one to
 * read in — so 900 m stays metres and 1,100 m becomes kilometres. Chosen so a
 * figure is between about one and a thousand of whatever it is written in,
 * which is the range a person can compare at a glance.
 */
const UNITS = {
    distance: {
        si: [
            { unit: 'm', per: 1, at: 0, decimals: 2 },
            { unit: 'km', per: 1000, at: 1000, decimals: 3 },
        ],
        imperial: [
            { unit: 'ft', per: 0.3048, at: 0, decimals: 1 },
            { unit: 'mi', per: 1609.344, at: 1609.344, decimals: 3 },
        ],
        // Offered as overrides rather than as part of a system, because
        // nobody's whole workflow is in chains.
        nautical: [{ unit: 'nmi', per: 1852, at: 0, decimals: 3 }],
        chains: [{ unit: 'ch', per: 20.1168, at: 0, decimals: 3 }],
    },
    area: {
        si: [
            { unit: 'm²', per: 1, at: 0, decimals: 2 },
            { unit: 'ha', per: 10_000, at: 10_000, decimals: 4 },
            { unit: 'km²', per: 1_000_000, at: 1_000_000, decimals: 4 },
        ],
        imperial: [
            { unit: 'ft²', per: 0.09290304, at: 0, decimals: 1 },
            { unit: 'ac', per: 4046.8564224, at: 4046.8564224, decimals: 4 },
            { unit: 'mi²', per: 2_589_988.110336, at: 2_589_988.110336, decimals: 4 },
        ],
        // Malaysian and Thai land measures, which the cadastre is still
        // partly written in.
        rai: [{ unit: 'rai', per: 1600, at: 0, decimals: 3 }],
        rood: [{ unit: 'rood', per: 1011.7141056, at: 0, decimals: 3 }],
    },
};

/**
 * The ladder itself, for a caller that does its own stepping.
 *
 * The scale bar is the one such caller: it shows metric and imperial at once
 * rather than choosing between them, and it rounds to a readable bar length
 * rather than to a fixed number of decimals. It still must not carry its own
 * factors — a foot defined in two places is a foot that can differ in one —
 * so it takes the ladder and does its own walking.
 *
 * Each step's `at` is the value at which it takes over, so the step before it
 * ends there.
 *
 * @return {Array<{unit: string, per: number, at: number, decimals: number}>}
 */
export function ladderFor(quantity, system = 'si') {
    return UNITS[quantity]?.[system] ?? [];
}

/**
 * The ladder of units for a quantity under a set of preferences.
 *
 * `{ system: 'si', area: 'rai' }` means SI for everything except area.
 */
function ladder(quantity, preferences = {}) {
    const name = preferences[quantity] ?? preferences.system ?? 'si';

    return UNITS[quantity]?.[name] ?? UNITS[quantity]?.si ?? [];
}

/**
 * A value in base units, as a number and a unit.
 *
 * Separated rather than returned as one string so a caller can lay the two out
 * — a table wants the figure right-aligned and the unit not.
 */
export function convert(value, quantity, preferences = {}) {
    const steps = ladder(quantity, preferences);
    const magnitude = Math.abs(value);
    let chosen = steps[0];

    for (const step of steps) {
        if (magnitude >= step.at) {
            chosen = step;
        }
    }

    if (!chosen) {
        return { value, unit: '', decimals: 2 };
    }

    return { value: value / chosen.per, unit: chosen.unit, decimals: chosen.decimals };
}

/** The same, as the string a label shows. */
export function format(value, quantity, preferences = {}) {
    const { value: converted, unit, decimals } = convert(value, quantity, preferences);

    return `${trim(converted, decimals)} ${unit}`;
}

/** A distance in metres, formatted. */
export function formatDistance(metres, preferences = {}) {
    return format(metres, 'distance', preferences);
}

/** An area in square metres, formatted. */
export function formatArea(squareMetres, preferences = {}) {
    return format(squareMetres, 'area', preferences);
}

/**
 * A bearing as degrees, minutes and seconds.
 *
 * How a title document writes one, which is the document a user is comparing
 * against. A decimal bearing is easier to compute with and harder to check.
 */
export function formatBearing(degrees) {
    const normalised = ((degrees % 360) + 360) % 360;
    const d = Math.floor(normalised);
    const minutesFloat = (normalised - d) * 60;
    const m = Math.floor(minutesFloat);
    const s = Math.round((minutesFloat - m) * 60);

    // Rounding seconds can carry: 12°59'60" is 13°00'00".
    if (s === 60) {
        return formatBearing(d + (m + 1) / 60 + 1e-9);
    }

    return `${d}°${String(m).padStart(2, '0')}'${String(s).padStart(2, '0')}"`;
}

/** Drop trailing zeros, so 1.500 km reads 1.5 km but 1.005 keeps its shape. */
function trim(value, decimals) {
    return String(Number(value.toFixed(decimals)));
}
