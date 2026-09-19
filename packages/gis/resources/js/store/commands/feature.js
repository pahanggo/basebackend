/**
 * Feature commands.
 *
 * Note what is NOT here: geometry. The store holds a feature's id, layer,
 * version and properties; its coordinates live in the renderer's typed arrays
 * (specification section 3). The `geom` a command carries is the base64 WKB on
 * its way to the server, held on the command, not in state.
 */

/**
 * `feature.create`.
 *
 * The id is a `tempId` until the server answers, because the UI is optimistic:
 * the parcel is drawn and selectable before the round trip completes. The
 * server echoes the `tempId` beside the id it assigned, and `reconcile()`
 * swaps them.
 */
export function featureCreate({ tempId, layerId, geom, properties = {}, geomEncoding = 'wkb' }) {
    return {
        op: 'feature.create',
        topics: ['features', `layers:${layerId}`],
        geom,

        apply(state) {
            state.features[tempId] = { id: tempId, layerId, properties: { ...properties }, version: 0 };

            const layer = state.layers[layerId];

            if (layer) {
                layer.featureCount = (layer.featureCount || 0) + 1;
            }

            return featureDelete({ id: tempId, layerId, version: 0, restore: { geom, properties } });
        },

        serialize() {
            // GeoJSON travels as a STRING, like the base64 WKB it is an
            // alternative to. The server reads whichever the encoding names,
            // and an object here would arrive as one it cannot parse.
            return {
                op: 'feature.create',
                tempId,
                layerId,
                geom: geomEncoding === 'geojson' && typeof geom !== 'string'
                    ? JSON.stringify(geom)
                    : geom,
                geomEncoding,
                properties,
            };
        },
    };
}

/**
 * `feature.update` — the op every geometry edit becomes.
 *
 * `properties` is a patch: the keys sent are the keys written, and a null value
 * removes one. That is what makes the server's per-field merge possible, and it
 * is why the inverse only has to carry the keys this command touched rather
 * than the whole document.
 */
export function featureUpdate({
    id,
    layerId,
    version,
    geom = null,
    previousGeom = null,
    properties = null,
    coalescable = false,
    geomEncoding = 'wkb',
}) {
    return {
        op: 'feature.update',
        topics: ['features', `features:${id}`, `layers:${layerId}`],
        coalescable,
        geom,

        apply(state) {
            const feature = state.features[id];
            const previousProperties = {};

            if (properties && feature) {
                for (const key of Object.keys(properties)) {
                    // `undefined` would serialise away; null is the removal
                    // signal on the wire, so an absent key inverts to null.
                    previousProperties[key] = Object.prototype.hasOwnProperty.call(feature.properties, key)
                        ? feature.properties[key]
                        : null;

                    if (properties[key] === null) {
                        delete feature.properties[key];
                    } else {
                        feature.properties[key] = properties[key];
                    }
                }
            }

            // Geometry is NOT written into state: the renderer owns coordinates
            // and the store holds metadata (specification section 3). The
            // command carries `geom` for the wire and `previousGeom` for its
            // inverse, and the caller — the drawing tool — is what has both,
            // because it is where the edit happened.
            if (feature) {
                feature.version += 1;
            }

            return featureUpdate({
                id,
                layerId,
                version: (feature?.version ?? version) + 1,
                geom: geom === null ? null : previousGeom,
                previousGeom: geom,
                properties: properties === null ? null : previousProperties,
                geomEncoding,
            });
        },

        serialize() {
            const payload = { op: 'feature.update', id, version };

            if (geom !== null) {
                // GeoJSON travels as a string, like the base64 WKB it stands
                // in for; an object arrives as something the server cannot
                // parse.
                payload.geom = geomEncoding === 'geojson' && typeof geom !== 'string'
                    ? JSON.stringify(geom)
                    : geom;
                payload.geomEncoding = geomEncoding;
            }

            if (properties !== null) {
                payload.properties = properties;
            }

            return payload;
        },
    };
}

/**
 * `feature.delete`.
 *
 * The inverse carries the whole feature, which is the one place an inverse is
 * larger than its command — and the reason the undo stack has a byte bound as
 * well as a depth bound.
 */
export function featureDelete({ id, layerId, version, restore = null }) {
    return {
        op: 'feature.delete',
        topics: ['features', `features:${id}`, `layers:${layerId}`],

        apply(state) {
            const feature = state.features[id];

            // Only the properties come from state; the geometry to restore is
            // handed in by the caller, which took it from the renderer before
            // the delete. This is the cost of keeping geometry out of the
            // store, and it is paid here rather than by every read.
            const snapshot = restore || { geom: null, properties: { ...(feature?.properties ?? {}) } };

            delete state.features[id];
            state.selection.delete(id);

            const layer = state.layers[layerId];

            if (layer && layer.featureCount > 0) {
                layer.featureCount -= 1;
            }

            return featureCreate({
                tempId: `restore:${id}`,
                layerId,
                geom: snapshot.geom,
                properties: snapshot.properties,
            });
        },

        serialize() {
            return { op: 'feature.delete', id, version };
        },
    };
}

/**
 * A vertex drag: one undo entry, one `feature.update` on the wire.
 *
 * This is the gesture granularity specification section 16 describes and the
 * only place the client and server vocabularies differ — and they differ in
 * granularity, not in meaning. `moveVertex`, `insertVertex`, `deleteVertex`,
 * `transform` and the boolean operations all resolve to the resulting geometry,
 * because the server has no reason to know which gesture produced it and five
 * near-identical ops would mean five validation paths.
 */
export function moveVertex({ id, layerId, version, geom, previousGeom }) {
    const command = featureUpdate({ id, layerId, version, geom, previousGeom, coalescable: true });

    return {
        ...command,
        op: 'feature.moveVertex',

        apply(state) {
            const inverse = command.apply(state);

            return { ...inverse, op: 'feature.moveVertex', coalescable: true };
        },
    };
}
