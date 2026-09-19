/**
 * Measurement commands.
 *
 * Measurements belong to the map rather than to a layer, so they are authorised
 * by the map role alone and never touch a placement or a layer lock.
 *
 * **The geometry is kept in client state, unlike a feature's.** A feature's
 * coordinates live in the renderer's typed arrays and come back from the
 * viewport read; a measurement is never read by viewport — it arrives whole in
 * the bootstrap and is drawn wherever it is, in or out of view. So the store is
 * the only place its geometry exists, and an optimistic create that dropped it
 * would paint nothing until the page was reloaded.
 *
 * `unit` is the unit PREFERENCE the measurement was taken under — `si`,
 * `imperial`, `rai` — and not the unit of `value`. `value` is always metres,
 * square metres or degrees, and `kind` says which of the three (specification
 * §11). Storing the preference is what lets a measurement read back the way it
 * was written when the reader's own preference has since changed.
 */

export function measurementCreate({ tempId, kind, geom, value = 0, unit = 'si', label = null, tool = null }) {
    return {
        op: 'measurement.create',
        topics: ['measurements'],

        apply(state) {
            state.measurements[tempId] = { id: tempId, kind, tool, geom, value, unit, label, version: 0 };

            return measurementDelete({ id: tempId, version: 0 });
        },

        serialize() {
            return {
                op: 'measurement.create',
                tempId,
                kind,
                // The wire takes a STRING, whatever the encoding — the write
                // path bounds the payload by its length before it parses it,
                // and there is no length to bound on an object. Held as an
                // object in state, because that is what the canvas draws.
                geom: wire(geom),
                geomEncoding: 'geojson',
                value,
                unit,
                label,
                tool,
            };
        },
    };
}

export function measurementUpdate({ id, version, geom = null, label = undefined, value = null }) {
    return {
        op: 'measurement.update',
        topics: ['measurements', `measurements:${id}`],

        apply(state) {
            const measurement = state.measurements[id];
            const wasLabel = measurement?.label ?? null;

            if (measurement) {
                if (label !== undefined) {
                    measurement.label = label;
                }

                if (geom !== null) {
                    measurement.geom = geom;
                }

                if (value !== null) {
                    measurement.value = value;
                }

                measurement.version += 1;
            }

            return measurementUpdate({
                id,
                version: (measurement?.version ?? version) + 1,
                label: label === undefined ? undefined : wasLabel,
            });
        },

        serialize() {
            const payload = { op: 'measurement.update', id, version };

            if (geom !== null) {
                payload.geom = wire(geom);
                payload.geomEncoding = 'geojson';
            }

            if (label !== undefined) {
                payload.label = label;
            }

            if (value !== null) {
                payload.value = value;
            }

            return payload;
        },
    };
}

export function measurementDelete({ id, version, restore = null }) {
    return {
        op: 'measurement.delete',
        topics: ['measurements', `measurements:${id}`],

        apply(state) {
            const measurement = state.measurements[id];
            const snapshot = restore || (measurement ? { ...measurement } : null);

            delete state.measurements[id];

            return measurementCreate({
                tempId: `restore:${id}`,
                kind: snapshot?.kind ?? 'distance',
                tool: snapshot?.tool ?? null,
                geom: snapshot?.geom ?? null,
                value: snapshot?.value ?? 0,
                unit: snapshot?.unit ?? 'si',
                label: snapshot?.label ?? null,
            });
        },

        serialize() {
            return { op: 'measurement.delete', id, version };
        },
    };
}

/** Geometry as the write path takes it: a GeoJSON document, as a string. */
function wire(geom) {
    return typeof geom === 'string' || geom === null ? geom : JSON.stringify(geom);
}
