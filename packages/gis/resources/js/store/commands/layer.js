/**
 * Layer commands.
 *
 * Two kinds, and the split is the whole point: a LAYER command changes what the
 * layer is, in every map showing it; a PLACEMENT command changes only where it
 * sits in this map. They carry different ids and guard different versions, and
 * sending one where the other is meant is a conflict — which is the intended
 * way to find that mistake (specification section 7).
 */

/** LAYER. Renames it everywhere. */
export function layerRename({ id, version, name, previousName }) {
    return {
        op: 'layer.rename',
        topics: ['layers', `layers:${id}`],

        apply(state) {
            const layer = state.layers[id];
            const was = previousName ?? layer?.name ?? '';

            if (layer) {
                layer.name = name;
                layer.version += 1;
            }

            return layerRename({ id, version: (layer?.version ?? version) + 1, name: was, previousName: name });
        },

        serialize() {
            return { op: 'layer.rename', id, version, name };
        },
    };
}

/**
 * LAYER. The whole style object, replaced.
 *
 * Classification and labels live inside it, so `setClassification` and
 * `setLabels` are gestures that both serialise to this. Replacement rather than
 * patch because a half-applied style — a graduated ramp with no field — renders
 * nothing.
 */
export function layerSetStyle({ id, version, style, previousStyle = null }) {
    return {
        op: 'layer.setStyle',
        topics: ['layers', `layers:${id}`, 'style'],

        apply(state) {
            const layer = state.layers[id];
            const was = previousStyle ?? (layer ? { ...layer.style } : {});

            if (layer) {
                layer.style = style;
                layer.version += 1;
            }

            return layerSetStyle({
                id,
                version: (layer?.version ?? version) + 1,
                style: was,
                previousStyle: style,
            });
        },

        serialize() {
            return { op: 'layer.setStyle', id, version, style };
        },
    };
}

/** PLACEMENT. Hides the layer in this map and nowhere else. */
export function layerSetVisible({ id, version, visible }) {
    return {
        op: 'layer.setVisible',
        topics: ['layers', `placements:${id}`],

        apply(state) {
            const placement = state.placements[id];

            if (placement) {
                placement.visible = visible;
                placement.version += 1;
            }

            return layerSetVisible({
                id,
                version: (placement?.version ?? version) + 1,
                visible: !visible,
            });
        },

        serialize() {
            return { op: 'layer.setVisible', id, version, visible };
        },
    };
}

/**
 * PLACEMENT. Writes one row.
 *
 * `sortKey` is a fractional index, so dropping a node between two siblings
 * renumbers none of them. The client computes the key because the client is
 * where the drop position is known; the server only validates its shape.
 */
export function layerReorder({ id, version, parentId, sortKey, previous = null }) {
    return {
        op: 'layer.reorder',
        topics: ['layers', 'tree', `placements:${id}`],

        apply(state) {
            const placement = state.placements[id];
            const was = previous ?? {
                parentId: placement?.parentId ?? null,
                sortKey: placement?.sortKey ?? null,
            };

            if (placement) {
                placement.parentId = parentId;
                placement.sortKey = sortKey;
                placement.version += 1;
            }

            return layerReorder({
                id,
                version: (placement?.version ?? version) + 1,
                parentId: was.parentId,
                sortKey: was.sortKey,
                previous: { parentId, sortKey },
            });
        },

        serialize() {
            return { op: 'layer.reorder', id, version, parentId, sortKey };
        },
    };
}

/**
 * PLACEMENT. Opacity, on every layer kind.
 *
 * Vector, raster, image and group alike, because opacity multiplies down the
 * chain: a group at 50% halves everything under it. Like visibility this is
 * this map's view of the layer rather than a change to the layer, so a `read`
 * placement may still set it.
 */
export function layerSetOpacity({ id, version, opacity, previousOpacity = null }) {
    return {
        op: 'layer.setOpacity',
        topics: ['layers', `placements:${id}`],

        apply(state) {
            const placement = state.placements[id];
            const was = previousOpacity ?? placement?.opacity ?? 1;

            if (placement) {
                placement.opacity = opacity;
                placement.version += 1;
            }

            return layerSetOpacity({
                id,
                version: (placement?.version ?? version) + 1,
                opacity: was,
                previousOpacity: opacity,
            });
        },

        serialize() {
            return { op: 'layer.setOpacity', id, version, opacity };
        },
    };
}

/**
 * PLACEMENT. The zoom band a layer is visible in.
 *
 * Both ends are nullable and null means unbounded, so the inverse has to carry
 * nulls rather than treat them as "unset" — otherwise undoing a narrowing
 * would leave the old bound in place.
 */
export function layerSetZoomRange({ id, version, minZoom, maxZoom, previous = null }) {
    return {
        op: 'layer.setZoomRange',
        topics: ['layers', 'tree', `placements:${id}`],

        apply(state) {
            const placement = state.placements[id];
            const was = previous ?? {
                minZoom: placement?.minZoom ?? null,
                maxZoom: placement?.maxZoom ?? null,
            };

            if (placement) {
                placement.minZoom = minZoom;
                placement.maxZoom = maxZoom;
                placement.version += 1;
            }

            return layerSetZoomRange({
                id,
                version: (placement?.version ?? version) + 1,
                minZoom: was.minZoom,
                maxZoom: was.maxZoom,
                previous: { minZoom, maxZoom },
            });
        },

        serialize() {
            return { op: 'layer.setZoomRange', id, version, minZoom, maxZoom };
        },
    };
}

/**
 * LAYER. The lock, which blocks editing everywhere the layer appears.
 *
 * A locked layer stays visible and selectable — it is not a hidden layer and
 * not a read-only placement. Those are three different statements and the tree
 * shows them differently.
 */
export function layerSetLocked({ id, version, locked }) {
    return {
        op: 'layer.setLocked',
        topics: ['layers', `layers:${id}`],

        apply(state) {
            const layer = state.layers[id];

            if (layer) {
                layer.locked = locked;
                layer.version += 1;
            }

            return layerSetLocked({ id, version: (layer?.version ?? version) + 1, locked: !locked });
        },

        serialize() {
            return { op: 'layer.setLocked', id, version, locked };
        },
    };
}

/**
 * PLACEMENT. Drops this map's placement; the layer survives elsewhere.
 *
 * Not a delete, and the tree must not present it as one. The inverse cannot be
 * a local re-insert: the placement comes back with a server-assigned id, so
 * undoing this is a `layer.share` and identity does not round-trip
 * (specification section 16).
 */
export function layerRemoveFromMap({ id, version, layerId, parentId = null, sortKey = null, access = 'read' }) {
    return {
        op: 'layer.removeFromMap',
        topics: ['layers', 'tree', `placements:${id}`],

        apply(state) {
            const placement = state.placements[id];
            const was = placement
                ? { parentId: placement.parentId, sortKey: placement.sortKey, access: placement.access }
                : { parentId, sortKey, access };

            delete state.placements[id];
            state.tree = state.tree.filter((node) => node !== id);

            return layerShare({ layerId: layerId ?? placement?.layerId, ...was });
        },

        serialize() {
            return { op: 'layer.removeFromMap', id, version };
        },
    };
}

/**
 * Place a layer already in the library into this map.
 *
 * The inverse of `layer.removeFromMap`, and also what the library modal sends.
 * It has no local `apply` worth the name: the placement it creates has a
 * server-assigned id, so the tree is re-read rather than guessed at.
 */
export function layerShare({ layerId, parentId = null, sortKey, access = 'read' }) {
    return {
        op: 'layer.share',
        topics: ['layers', 'tree'],

        apply() {
            return layerShare({ layerId, parentId, sortKey, access });
        },

        serialize() {
            return { op: 'layer.share', layerId, mapId: null, parentId, sortKey, access };
        },
    };
}

/**
 * Wrap nodes in a new group, and its inverse.
 *
 * Both sides re-read the tree rather than applying locally: the group's
 * placement id is the server's to assign, and every child's `parentId` now
 * points at it. Guessing either would put the client's tree and the server's
 * out of step in a way only a reload would fix.
 */
export function layerGroup({ tempId, name, placementIds, parentId = null, sortKey }) {
    return {
        op: 'layer.group',
        topics: ['layers', 'tree'],

        apply() {
            return layerUngroup({ id: tempId, version: 1 });
        },

        serialize() {
            return { op: 'layer.group', tempId, name, placementIds, parentId, sortKey };
        },
    };
}

/** Dissolve a group, lifting its children to the group's own parent. */
export function layerUngroup({ id, version }) {
    return {
        op: 'layer.ungroup',
        topics: ['layers', 'tree'],

        apply() {
            return layerUngroup({ id, version: version + 1 });
        },

        serialize() {
            return { op: 'layer.ungroup', id, version };
        },
    };
}

/**
 * A new, empty layer owned by this map.
 *
 * Like `layer.group`, both the layer and its placement come back with
 * server-assigned ids, so this has no meaningful local `apply` and the tree is
 * re-read once the batch confirms. Guessing an id here would put the client's
 * tree and the server's out of step in a way only a reload would fix.
 */
export function layerCreate({ tempId, name, kind = 'vector', style = {}, parentId = null, sortKey }) {
    return {
        op: 'layer.create',
        topics: ['layers', 'tree'],

        apply() {
            return layerCreate({ tempId, name, kind, style, parentId, sortKey });
        },

        serialize() {
            return { op: 'layer.create', tempId, name, kind, style, parentId, sortKey };
        },
    };
}

/**
 * PLACEMENT. Split this layer into sublayers, or stop.
 *
 * **A placement command, not a layer one, and the reason is the data.** Style
 * is layer-level and `layer.setStyle` refuses a locked layer — and every
 * imported layer is locked, so a classification kept in the style could never
 * be applied to the only layers that have anything to classify. It is also the
 * right shape: how a map reads a shared layer is that map's business, the same
 * as visibility and opacity.
 *
 * Whole replacement, like `layer.setStyle`: a document naming a field with no
 * classes paints nothing. One class's colours go through `layerSetClassState`.
 */
export function layerSetClassification({ id, version, classification, previous = null }) {
    return {
        op: 'layer.setClassification',
        topics: ['layers', 'tree', `placements:${id}`, 'style'],

        apply(state) {
            const placement = state.placements[id];
            const was = previous ?? placement?.classification ?? null;

            if (placement) {
                placement.classification = classification;
                placement.version += 1;
            }

            return layerSetClassification({
                id,
                version: (placement?.version ?? version) + 1,
                classification: was,
                previous: classification,
            });
        },

        serialize() {
            return { op: 'layer.setClassification', id, version, classification };
        },
    };
}

/**
 * PLACEMENT. One sublayer's checkbox, slider or colours.
 *
 * A patch of one class rather than a rewrite of the document, so dimming one
 * category does not collide with somebody hiding another.
 *
 * Addressed by `value`, never by position: reordering the classes would
 * otherwise send a command in flight to whichever category moved into its slot.
 */
export function layerSetClassState({ id, version, value, state: patch, previous = null }) {
    return {
        op: 'layer.setClassState',
        topics: ['layers', 'tree', `placements:${id}`, 'style'],

        apply(state) {
            const placement = state.placements[id];
            const current = classAt(placement?.classification, value);
            const was = previous ?? pick(current, Object.keys(patch));

            if (placement && current) {
                Object.assign(current, patch);
                placement.version += 1;
            }

            return layerSetClassState({
                id,
                version: (placement?.version ?? version) + 1,
                value,
                state: was,
                previous: pick(current, Object.keys(patch)),
            });
        },

        serialize() {
            return { op: 'layer.setClassState', id, version, value, ...patch };
        },
    };
}

/**
 * One class of a classification, by value.
 *
 * `other` names the bucket for values no class claims. A class whose own value
 * is the string `other` is found first, because the list is searched before the
 * bucket — the same order the server uses.
 */
export function classAt(classification, value) {
    if (!classification) {
        return null;
    }

    const found = (classification.classes ?? []).find((entry) => entry.value === value);

    if (found) {
        return found;
    }

    return value === 'other' ? (classification.other ??= {}) : null;
}

/** The named keys of an object, for recording what a patch is about to cover. */
function pick(source, keys) {
    const out = {};

    for (const key of keys) {
        out[key] = source ? source[key] : undefined;
    }

    return out;
}
