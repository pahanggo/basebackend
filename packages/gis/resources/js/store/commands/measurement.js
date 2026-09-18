/**
 * Measurement commands.
 *
 * Measurements belong to the map rather than to a layer, so they are authorised
 * by the map role alone and never touch a placement or a layer lock. S10 gives
 * them their units and labels; here they exist so the command pipeline is
 * exercised by something that is not a feature.
 */

export function measurementCreate({ tempId, kind, geom, value = 0, unit = 'm', label = null }) {
    return {
        op: 'measurement.create',
        topics: ['measurements'],

        apply(state) {
            state.measurements[tempId] = { id: tempId, kind, value, unit, label, version: 0 };

            return measurementDelete({ id: tempId, version: 0 });
        },

        serialize() {
            return { op: 'measurement.create', tempId, kind, geom, value, unit, label };
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
                payload.geom = geom;
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
                geom: snapshot?.geom ?? null,
                value: snapshot?.value ?? 0,
                unit: snapshot?.unit ?? 'm',
                label: snapshot?.label ?? null,
            });
        },

        serialize() {
            return { op: 'measurement.delete', id, version };
        },
    };
}
