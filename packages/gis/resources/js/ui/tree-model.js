/**
 * The layer tree as data: placements in, flat rows out.
 *
 * Kept free of the DOM so the parts that are easy to get wrong — inheritance,
 * tri-state, the invalid-drop rule, where a drop actually lands — are ordinary
 * functions with ordinary tests. `layer-tree.js` is the view over this and
 * holds no tree arithmetic of its own.
 *
 * The tree is stored as a flat list of placements each naming a `parentId` and
 * a fractional `sortKey`, never as nested arrays. That is what lets a drag
 * write one row (specification section 8), and it means every question here is
 * answered by sorting siblings rather than by splicing a structure.
 */

import { between } from '../lib/sort-key.js';

/**
 * A parent-to-children index, built in one pass.
 *
 * Without it every question about the tree is a scan of the whole tree, and
 * the answers are asked per node: flattening 2,000 nodes cost 98 ms and
 * expanding a group 67 ms, against a 50 ms gate, because both were quietly
 * quadratic. With it they are 4 ms and 3 ms.
 *
 * It is a snapshot. Build one per render pass and throw it away — holding one
 * across a mutation is how a tree starts disagreeing with the store.
 */
export function buildIndex(state) {
    const byParent = new Map();

    for (const id of state.tree) {
        const placement = state.placements[id];

        if (!placement) {
            continue;
        }

        const parent = placement.parentId ?? null;

        if (!byParent.has(parent)) {
            byParent.set(parent, []);
        }

        byParent.get(parent).push(placement);
    }

    for (const children of byParent.values()) {
        children.sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : a.id - b.id));
    }

    return byParent;
}

/**
 * Children of a node, in sort order. `null` is the root.
 *
 * Pass an index when asking repeatedly; without one this scans the whole tree,
 * which is fine for a single question and quadratic for a traversal.
 */
export function childrenOf(state, parentId = null, index = null) {
    if (index) {
        return index.get(parentId) ?? [];
    }

    return state.tree
        .map((id) => state.placements[id])
        .filter((placement) => placement && (placement.parentId ?? null) === parentId)
        .sort((a, b) => (a.sortKey < b.sortKey ? -1 : a.sortKey > b.sortKey ? 1 : a.id - b.id));
}

export function isGroup(state, placement) {
    return state.layers[placement.layerId]?.kind === 'group';
}

/** Every placement beneath a node, at any depth. */
export function descendantsOf(state, parentId, index = null) {
    const idx = index ?? buildIndex(state);
    const out = [];
    const walk = (id) => {
        for (const child of childrenOf(state, id, idx)) {
            out.push(child);
            walk(child.id);
        }
    };

    walk(parentId);

    return out;
}

/**
 * Would dropping `node` onto `target` put a group inside itself?
 *
 * Rejected visually before release rather than refused on the server, because
 * the user should never be able to complete a gesture that cannot work — but
 * the server refuses it too, since a drop is not an authorization boundary any
 * more than a modal is.
 */
export function wouldCycle(state, nodeId, targetParentId) {
    if (targetParentId === null) {
        return false;
    }

    if (nodeId === targetParentId) {
        return true;
    }

    return descendantsOf(state, nodeId).some((child) => child.id === targetParentId);
}

/**
 * Effective visibility: own flag, every ancestor's flag, and the zoom band.
 *
 * Specification section 8. The three are combined here and nowhere else, so a
 * layer that is not drawn has exactly one function to ask why.
 */
export function effectiveVisible(state, placement, zoom = null) {
    let node = placement;

    for (let depth = 0; node && depth < 64; depth += 1) {
        if (!node.visible) {
            return false;
        }

        node = node.parentId === null || node.parentId === undefined
            ? null
            : state.placements[node.parentId];
    }

    if (zoom === null) {
        return true;
    }

    const min = placement.minZoom;
    const max = placement.maxZoom;

    return (min === null || min === undefined || zoom >= min)
        && (max === null || max === undefined || zoom <= max);
}

/** Opacity multiplies down the chain, so a group at 50% halves what is under it. */
export function effectiveOpacity(state, placement) {
    let value = 1;
    let node = placement;

    for (let depth = 0; node && depth < 64; depth += 1) {
        value *= node.opacity ?? 1;
        node = node.parentId === null || node.parentId === undefined
            ? null
            : state.placements[node.parentId];
    }

    return value;
}

/**
 * A group's checkbox state: true, false, or `null` for indeterminate.
 *
 * Indeterminate means the descendants disagree. A group whose own flag is off
 * reads as off whatever its children say — the children are still there, and
 * rechecking the group restores them, which is the behaviour the specification
 * asks for and the reason toggling a group must not overwrite them.
 */
export function groupCheckState(state, placement, index = null) {
    if (!placement.visible) {
        return false;
    }

    const descendants = descendantsOf(state, placement.id, index);

    if (descendants.length === 0) {
        return true;
    }

    const on = descendants.filter((child) => child.visible).length;

    if (on === descendants.length) {
        return true;
    }

    return on === 0 ? false : null;
}

/**
 * The rows to render, depth-first, skipping anything inside a collapsed group.
 *
 * A filter narrows to matching nodes **and their ancestors**, so a match deep
 * in a collapsed group is reachable rather than merely counted. Matching is on
 * layer name only — feature attributes are Search and Query's job, and giving
 * the tree filter an endpoint would give two controls the same work
 * (specification section 8).
 *
 * A classified layer also emits one row per class, below it. Those rows are a
 * third kind, after group and leaf: they are not placements, nothing may be
 * dropped on them and they cannot be dragged. `kind` tells them apart, and
 * every consumer that reaches for `row.layer.name` must check it.
 *
 * @returns {Array<{kind: string, placement: Object, layer: Object, depth: number, hasChildren: boolean, matched: boolean}>}
 */
export function flatten(state, { collapsed = new Set(), filter = '', index = null } = {}) {
    const idx = index ?? buildIndex(state);
    const needle = filter.trim().toLowerCase();
    const keep = needle === '' ? null : matchingWithAncestors(state, needle);
    const rows = [];

    const walk = (parentId, depth) => {
        for (const placement of childrenOf(state, parentId, idx)) {
            if (keep && !keep.has(placement.id)) {
                continue;
            }

            const layer = state.layers[placement.layerId];

            if (!layer) {
                continue;
            }

            const children = childrenOf(state, placement.id, idx);

            const classes = classRowsOf(placement);

            rows.push({
                kind: 'node',
                placement,
                layer,
                depth,
                hasChildren: children.length > 0 || classes.length > 0,
                matched: needle !== '' && layer.name.toLowerCase().includes(needle),
            });

            const open = !collapsed.has(placement.id) || keep;

            // Sublayers sit directly under their layer and are always leaves.
            if (open && classes.length > 0) {
                for (const entry of classes) {
                    rows.push({
                        kind: 'class',
                        placement,
                        layer,
                        depth: depth + 1,
                        hasChildren: false,
                        matched: false,
                        value: entry.value,
                        label: entry.label || entry.value,
                        classEntry: entry,
                    });
                }
            }

            // A filter expands what it matches into: hiding a match inside a
            // collapsed group would report a hit the user cannot reach.
            if (children.length > 0 && open) {
                walk(placement.id, depth + 1);
            }
        }
    };

    walk(null, 0);

    return rows;
}

/**
 * A placement's sublayer rows, in the classification's own order.
 *
 * The "other" bucket is appended only once something has been classified, and
 * it is a row like any other so that the features no class claims can be dimmed
 * or hidden rather than being invisible state.
 */
export function classRowsOf(placement) {
    const classification = placement?.classification;

    if (!classification || !Array.isArray(classification.classes) || classification.classes.length === 0) {
        return [];
    }

    return [
        ...classification.classes,
        { ...(classification.other ?? {}), value: 'other', isOther: true },
    ];
}

/** Is this layer split into sublayers in this map? */
export function isClassified(placement) {
    return classRowsOf(placement).length > 0;
}

function matchingWithAncestors(state, needle) {
    const keep = new Set();

    for (const id of state.tree) {
        const placement = state.placements[id];
        const layer = placement && state.layers[placement.layerId];

        if (!layer || !layer.name.toLowerCase().includes(needle)) {
            continue;
        }

        let node = placement;

        for (let depth = 0; node && depth < 64; depth += 1) {
            keep.add(node.id);
            node = node.parentId === null || node.parentId === undefined
                ? null
                : state.placements[node.parentId];
        }
    }

    return keep;
}

/**
 * Where a drop lands: the parent it joins and the sort key it takes.
 *
 * `position` is one of the three drop targets the specification names — above
 * a node, below a node, or into a group — and each resolves to a different
 * pair of siblings to sit between. Returns null when the drop is invalid, so
 * the caller has one thing to check rather than three.
 *
 * `after` is the upper bound the key was cut against, or null for an open end.
 * A multi-node drop chains against it so the second node lands between the
 * first and the same bound — appending a digit per node instead would grow the
 * key by one character for every node in the block, which is the exact failure
 * fractional indexing exists to avoid.
 *
 * @param {'above'|'below'|'into'} position
 */
export function resolveDrop(state, nodeId, targetId, position) {
    const target = state.placements[targetId];

    if (!target) {
        return null;
    }

    if (position === 'into') {
        if (!isGroup(state, target) || wouldCycle(state, nodeId, target.id)) {
            return null;
        }

        const siblings = childrenOf(state, target.id).filter((child) => child.id !== nodeId);
        const last = siblings.length > 0 ? siblings[siblings.length - 1].sortKey : null;

        return { parentId: target.id, sortKey: between(last, null), after: null };
    }

    const parentId = target.parentId ?? null;

    if (wouldCycle(state, nodeId, parentId)) {
        return null;
    }

    const siblings = childrenOf(state, parentId).filter((child) => child.id !== nodeId);
    const index = siblings.findIndex((child) => child.id === target.id);

    if (index === -1) {
        // The target was the node being dragged and is now filtered out: a drop
        // onto itself, which changes nothing.
        return null;
    }

    const before = position === 'above'
        ? (index > 0 ? siblings[index - 1].sortKey : null)
        : siblings[index].sortKey;

    const after = position === 'above'
        ? siblings[index].sortKey
        : (index + 1 < siblings.length ? siblings[index + 1].sortKey : null);

    return { parentId, sortKey: between(before, after), after };
}

/**
 * Which of the three drop targets a pointer is over.
 *
 * The middle half of a group row is "into"; on a leaf there is no middle,
 * because a leaf cannot contain anything and offering it would be a target
 * that rejects every drop.
 *
 * @param {number} fraction 0 at the row's top edge, 1 at its bottom
 */
export function dropPosition(fraction, targetIsGroup) {
    if (!targetIsGroup) {
        return fraction < 0.5 ? 'above' : 'below';
    }

    if (fraction < 0.25) {
        return 'above';
    }

    return fraction > 0.75 ? 'below' : 'into';
}
