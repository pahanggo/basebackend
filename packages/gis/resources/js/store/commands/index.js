/**
 * The client's command vocabulary.
 *
 * It is the same vocabulary the API speaks — the objects pushed onto the undo
 * stack are the objects sent to the server (specification section 7). There is
 * no adapter here converting one into the other, and there must never be: a
 * translation layer between store commands and API payloads means the design
 * has gone wrong.
 *
 * The one asymmetry is granularity, not meaning. A vertex drag is a distinct
 * undo entry on the client and a `feature.update` on the wire, because the
 * server has no reason to know which gesture produced a geometry.
 */

export { featureCreate, featureUpdate, featureDelete, moveVertex } from './feature.js';
export { layerRename, layerSetStyle, layerSetVisible, layerReorder } from './layer.js';
export { measurementCreate, measurementUpdate, measurementDelete } from './measurement.js';

/**
 * Fold the server's answer back into the store.
 *
 * Two things happen here and both are necessary. Optimistic rows created under
 * a `tempId` take the id the server assigned, and every touched row takes the
 * version the server returned — because the next command on that row has to
 * carry a version the server will recognise, and the optimistic increment is
 * only a guess.
 *
 * @param {Object} state
 * @param {Array<Object>} applied the `applied` array from a command response
 */
export function reconcile(state, applied) {
    const collections = {
        feature: state.features,
        measurement: state.measurements,
        layer: state.layers,
        placement: state.placements,
    };

    for (const entry of applied) {
        const collection = collections[entry.entity];

        if (!collection) {
            continue;
        }

        if (entry.tempId && collection[entry.tempId]) {
            const row = collection[entry.tempId];

            delete collection[entry.tempId];

            row.id = entry.id;
            collection[entry.id] = row;

            if (state.selection.delete(entry.tempId)) {
                state.selection.add(entry.id);
            }
        }

        if (collection[entry.id]) {
            collection[entry.id].version = entry.version;
        }
    }
}

/**
 * Rows the server merged rather than conflicted.
 *
 * Worth surfacing: the user's edit went in alongside someone else's, and a
 * merge nobody is told about is indistinguishable from a lost edit.
 */
export function mergedRows(applied) {
    return applied.filter((entry) => entry.merged === true);
}
