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
