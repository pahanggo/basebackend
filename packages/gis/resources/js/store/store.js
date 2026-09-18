/**
 * The store: a plain object, mutated through exactly one function.
 *
 * Everything that makes this design worth having follows from that single
 * mutation point — undo, server sync and the diagnostic ring buffer are three
 * readings of the same stream of commands, not three mechanisms. Reach past
 * `commit()` to mutate state directly and all three go quietly wrong at once.
 *
 * Subscription is by explicit topic string, never dependency tracking. No
 * proxies, no getters, no dirty-checking: if state changed and the UI did not
 * update, the cause is a missing topic in `command.topics`, and that is one
 * place to look rather than a graph to trace (specification section 3).
 *
 * Geometry never enters here. The renderer owns coordinates; the store holds
 * metadata, style, tree and selection, which is what keeps it small enough to
 * serialise into a bug report.
 */

import { UndoStack } from './undo.js';
import { RingBuffer } from './ring-buffer.js';

/** @returns {Object} the initial shape, so a fresh store is never undefined-shaped */
export function initialState() {
    return {
        map: { id: null, name: '', version: 0 },
        layers: {},         // layerId -> identity: name, kind, style, locked, version
        placements: {},     // placementId -> this map's view of a layer
        tree: [],           // ordered placement ids, each { id, parentId, sortKey }
        features: {},       // id -> metadata only; geometry lives in the renderer
        measurements: {},
        selection: new Set(),
        ui: { activeTool: null, docks: {}, units: 'si' },
    };
}

export class Store {
    constructor({ state = initialState(), sync = null, undo = new UndoStack() } = {}) {
        this.state = state;
        this.sync = sync;
        this.undo = undo;
        this.subscribers = new Map();

        // The practical payoff of routing every mutation through one function:
        // a bug report carries the last 200 commands plus store state and
        // reproduces deterministically. It is free here and impossible to add
        // later without the single mutation point.
        this.history = new RingBuffer(200);
    }

    /**
     * Apply a command, record its inverse, queue it for the server, notify.
     *
     * The order matters. The inverse comes from `apply` rather than being
     * recomputed, so undo never has to re-derive what a command did — and a
     * command that returns a wrong inverse is a bug that shows up as a wrong
     * undo rather than as silent divergence.
     *
     * @param {Object} command
     * @param {Object} [options]
     * @param {boolean} [options.local] do not send; used when replaying the server's own echo
     * @param {boolean} [options.undoable] false for commands that should not be undoable
     */
    commit(command, { local = false, undoable = true } = {}) {
        const inverse = command.apply(this.state);

        this.history.push({ op: command.op, at: Date.now(), payload: command.serialize?.() ?? null });

        if (undoable) {
            this.undo.push(command, inverse);
        }

        if (!local && this.sync) {
            this.sync.enqueue(command);
        }

        this.emit(command.topics || []);

        return inverse;
    }

    /**
     * Apply a command without queueing or recording it.
     *
     * For the server's echo of someone else's work: it is already applied on
     * the server, and it is not this user's to undo.
     */
    applyRemote(command) {
        command.apply(this.state);
        this.emit(command.topics || []);
    }

    undoOne() {
        const entry = this.undo.pop();

        if (!entry) {
            return null;
        }

        // The inverse goes through `commit` so the server sees it as an
        // ordinary command — there is no "undo" op on the wire, because undo of
        // a move IS a move. One vocabulary (specification section 16).
        const redo = this.commit(entry.inverse, { undoable: false });

        this.undo.pushRedo(entry.inverse, redo);

        return entry;
    }

    redoOne() {
        const entry = this.undo.popRedo();

        if (!entry) {
            return null;
        }

        const inverse = this.commit(entry.inverse, { undoable: false });

        this.undo.push(entry.inverse, inverse, { coalesce: false });

        return entry;
    }

    /**
     * @param {string} topic coarse and explicit: `layers`, `layers:42`, `selection`
     * @returns {Function} unsubscribe
     */
    subscribe(topic, handler) {
        if (!this.subscribers.has(topic)) {
            this.subscribers.set(topic, new Set());
        }

        this.subscribers.get(topic).add(handler);

        return () => {
            this.subscribers.get(topic)?.delete(handler);
        };
    }

    emit(topics) {
        for (const topic of topics) {
            const handlers = this.subscribers.get(topic);

            if (!handlers) {
                continue;
            }

            for (const handler of handlers) {
                handler(this.state, topic);
            }
        }
    }

    /**
     * Store state plus the last 200 commands: everything a deterministic
     * reproduction needs, and small enough to paste into an issue.
     */
    diagnostics() {
        return {
            state: {
                ...this.state,
                selection: [...this.state.selection],
            },
            commands: this.history.toArray(),
            queue: this.sync ? this.sync.describe() : null,
        };
    }
}
