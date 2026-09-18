/**
 * Conflict resolution, client side.
 *
 * Most conflicts never reach here. The server merges a stale command whose
 * fields nobody else touched and reports it as merged, which is the same
 * machinery real-time co-editing needs and the reason it is built in v1
 * (specification section 16). What arrives here is the residue: two sides wrote
 * the same field.
 *
 * Three resolutions, and only three:
 *
 * - **keep mine** — retry the same command carrying the server's version
 * - **keep theirs** — discard the local command and adopt the server's state
 * - **merge** — per field, for attributes only
 *
 * **Geometry is never merged.** Not because it is hard to implement but because
 * nobody has decided what merging two edits to the same ring should mean. One
 * side wins, and the user picks which.
 */

export const KEEP_MINE = 'mine';
export const KEEP_THEIRS = 'theirs';
export const MERGE = 'merge';

/**
 * Pair each conflict with the command that caused it, so the panel can show a
 * local-versus-server comparison rather than two lists the user has to align.
 *
 * @param {Object} problem the 409 problem document
 * @param {Array<Object>} commands the batch that was rejected
 */
export function pairConflicts(problem, commands) {
    const serialized = commands.map((command) => ({ command, payload: command.serialize() }));

    return (problem.conflicts || []).map((conflict) => ({
        conflict,
        local: serialized.find(({ payload }) => Number(payload.id) === Number(conflict.id))?.command ?? null,
    }));
}

/**
 * The commands to send after the user has chosen.
 *
 * `keep theirs` returns nothing to send — the local command is dropped and the
 * caller applies the server's state to the store instead. It is the one
 * resolution that is a store operation rather than a queue operation.
 *
 * @param {Object} pair from `pairConflicts`
 * @param {string} choice KEEP_MINE | KEEP_THEIRS | MERGE
 * @param {Array<string>} [fields] for MERGE: the fields to keep from the local side
 */
export function resolve(pair, choice, fields = []) {
    const { conflict, local } = pair;

    if (choice === KEEP_THEIRS || local === null) {
        return { send: [], adopt: conflict.server };
    }

    const payload = local.serialize();

    if (choice === KEEP_MINE) {
        // The same command, re-versioned. The user saw the server's state and
        // chose to overwrite it, so this is not a lost update — it is a
        // decision, which is exactly what the panel exists to obtain.
        return { send: [{ ...payload, version: conflict.serverVersion }], adopt: null };
    }

    const merged = { ...payload, version: conflict.serverVersion };

    // Keep only the property keys the user chose; the rest of the server's
    // document stays as it is, because a property patch writes what it names.
    if (merged.properties) {
        merged.properties = Object.fromEntries(
            Object.entries(merged.properties).filter(([key]) => fields.includes(`properties.${key}`)),
        );
    }

    if (merged.geom && !fields.includes('geom')) {
        delete merged.geom;
    }

    return { send: [merged], adopt: conflict.server };
}
