/**
 * Turning a classification document into something the paint loop can use.
 *
 * Pure arithmetic over plain objects, kept away from the renderer and the tree
 * for the same reason the tree's arithmetic lives in `tree-model.js`: these are
 * the parts that fail silently. A class resolved to the wrong slot paints the
 * wrong colour, which no exception reports and no screenshot obviously shows.
 *
 * The indirection to understand before reading further:
 *
 *   feature  -> `geometry.classes[f]`  a DICTIONARY index, from the wire
 *   dictionary index -> `slotOf[i]`    a PAINT SLOT, from the classification
 *   paint slot -> `paints[slot]`       colours, opacity and visibility
 *
 * The middle step exists so that recolouring, hiding or re-splitting a category
 * never touches the features. The dictionary holds raw values and is built once
 * per read; the slot map and the paint table are rebuilt from the
 * classification, which is cheap and happens whenever someone moves a slider.
 */

import { OTHER } from '../../data/accumulator.js';

/**
 * Qualitative colours, ColorBrewer's Paired then Set3.
 *
 * Qualitative rather than sequential because a category has no order: a ramp
 * from pale to dark says "more" about a value that is merely different, and
 * land use has no more-ness. Twelve is ColorBrewer's ceiling per set, and the
 * land-use taxonomy has fourteen values, so two sets are concatenated and the
 * list wraps beyond twenty-four.
 */
export const PALETTE = [
    '#a6cee3', '#1f78b4', '#b2df8a', '#33a02c', '#fb9a99', '#e31a1c',
    '#fdbf6f', '#ff7f00', '#cab2d6', '#6a3d9a', '#ffff99', '#b15928',
    '#8dd3c7', '#ffffb3', '#bebada', '#fb8072', '#80b1d3', '#fdb462',
    '#b3de69', '#fccde5', '#d9d9d9', '#bc80bd', '#ccebc5', '#ffed6f',
];

/**
 * A darker relative of a fill, for its outline.
 *
 * Derived rather than chosen from a second palette so that a colour the user
 * picks gets an outline that still belongs to it. Multiplying each channel
 * keeps the hue and drops the value, which is what "the same colour, darker"
 * means to an eye.
 */
export function darken(hex, factor = 0.6) {
    const value = parseInt(hex.slice(1), 16);
    const channel = (shift) => Math.round(((value >> shift) & 0xff) * factor);

    return `#${[channel(16), channel(8), channel(0)]
        .map((c) => c.toString(16).padStart(2, '0'))
        .join('')}`;
}

/**
 * A fresh classification over a field and the values it was found to have.
 *
 * Every class starts visible, opaque and coloured, because a classification
 * that arrives colourless would repaint the layer one flat colour and look
 * like it had failed.
 */
export function defaultClassification(field, values) {
    return {
        field,
        classes: values.map((value, index) => {
            const fill = PALETTE[index % PALETTE.length];

            return {
                value,
                label: value,
                visible: true,
                opacity: 1,
                style: { fill, stroke: darken(fill) },
            };
        }),
        other: { label: '', visible: true, opacity: 1, style: {} },
    };
}

/**
 * One class's style overrides, as a plain object.
 *
 * **An empty style arrives as `[]`, not `{}`.** PHP does not distinguish an
 * empty map from an empty list, so a class nobody has recoloured is encoded as
 * a JSON array — and `[].fill` is `Array.prototype.fill`, a function rather
 * than `undefined`. So `entry.style?.fill ?? fallback` yields a function, the
 * fallback never runs, and assigning it to a CSS property silently produces an
 * empty string. Nothing throws and nothing is logged; a swatch is simply blank.
 *
 * Every read of a class's style goes through here for that reason.
 */
export function classStyle(entry) {
    const style = entry?.style;

    return style && !Array.isArray(style) ? style : {};
}

/**
 * Where each class sits in the paint table.
 *
 * The "other" bucket is last, so a slot is always a valid index and the table
 * never has a hole in it.
 */
export function otherSlot(classification) {
    return classification.classes.length;
}

/**
 * Dictionary index to paint slot, as a lookup the paint loop can index
 * directly.
 *
 * A `Uint8Array` of 256 rather than a `Map`: this is read once per feature in
 * the path build, tens of thousands of times, and a typed array indexed by a
 * byte is the cheapest lookup there is. Values the classification does not name
 * fall to the "other" slot, which is the honest answer — the data has values
 * nobody has classified yet, and painting them as a class they are not would be
 * worse than painting them as the leftovers they are.
 */
export function slotMap(classification, dictionary) {
    const fallback = otherSlot(classification);
    const slots = new Uint8Array(256).fill(fallback);
    const byValue = new Map();

    classification.classes.forEach((entry, index) => byValue.set(entry.value, index));

    for (let i = 0; i < dictionary.length && i < OTHER; i++) {
        const slot = byValue.get(dictionary[i]);

        if (slot !== undefined) {
            slots[i] = slot;
        }
    }

    return slots;
}

/**
 * The paint table: one entry per class, then the "other" bucket.
 *
 * `style` is merged over the layer's own, so a class that sets only a fill
 * keeps the layer's line width, and a classification carrying no colours at all
 * still paints exactly as the unclassified layer did.
 *
 * `opacity` is the class's alone and is multiplied by the layer's at paint
 * time. That is not the second alpha section 10 forbids — that was
 * `style.fillOpacity`, a second control over one object. Opacity has always
 * multiplied down the tree, a group through a layer; a class is one more level
 * of the same chain, and the only control over its own level.
 */
export function paintTable(classification, layerStyle = null) {
    const base = layerStyle ?? {};

    const entry = (item) => ({
        style: { ...base, ...classStyle(item) },
        opacity: item.opacity ?? 1,
        visible: item.visible !== false,
        label: item.label ?? '',
    });

    return [...classification.classes.map(entry), entry(classification.other ?? {})];
}

/**
 * Is this document usable as a classification?
 *
 * A placement can carry one written before its layer's schema changed, or by a
 * version of this application that is no longer running. The tree asks before
 * rendering sublayer rows and the feed asks before requesting a class column,
 * so a stale document degrades to an ordinary layer rather than to an error.
 */
export function isUsable(classification) {
    return Boolean(
        classification
        && typeof classification.field === 'string'
        && Array.isArray(classification.classes)
        && classification.classes.length > 0,
    );
}

/**
 * How many distinct paint states this classification produces.
 *
 * Section 10 warns above 64: canvas batching is what keeps the repaint inside
 * its budget, and each state is a fill, a stroke and the state changes between
 * them. Hidden classes do not count — they are never painted.
 */
export function paintStateCount(classification) {
    return paintTable(classification).filter((entry) => entry.visible).length;
}
