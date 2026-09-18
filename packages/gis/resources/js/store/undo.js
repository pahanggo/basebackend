/**
 * The undo stack.
 *
 * It holds inverses, not descriptions of how to compute them: `apply` returned
 * the inverse at commit time, when the previous state was in hand, and undoing
 * is applying it. Recomputing an inverse later means reading state that has
 * moved on, which is how undo stacks drift from what they claim to restore.
 *
 * Bounds, from specification section 16:
 *
 * - depth 100, or 50 MB of retained inverse payloads, whichever comes first
 * - one gesture is one entry: a vertex drag coalesces within 500 ms
 * - redo clears on any new command
 * - per map, and it does not survive a reload in v1
 */

export const MAX_DEPTH = 100;
export const MAX_BYTES = 50 * 1024 * 1024;
export const COALESCE_MS = 500;

/**
 * Roughly how much an inverse retains.
 *
 * Deliberately an estimate. The alternative is walking the payload on every
 * commit, which puts a traversal on the drag path to defend a bound that only
 * has to be approximately right — the point is to stop the stack growing
 * without limit, not to know its size to the byte.
 */
export function estimateBytes(command) {
    const payload = command?.inverseSize;

    if (typeof payload === 'number') {
        return payload;
    }

    const serialized = command?.serialize?.();

    if (!serialized) {
        return 256;
    }

    // Two bytes per character is the worst case for UTF-16 in the engines this
    // runs on; base64 WKB, which dominates, is one.
    return JSON.stringify(serialized).length * 2;
}

export class UndoStack {
    constructor({ maxDepth = MAX_DEPTH, maxBytes = MAX_BYTES, coalesceMs = COALESCE_MS } = {}) {
        this.maxDepth = maxDepth;
        this.maxBytes = maxBytes;
        this.coalesceMs = coalesceMs;
        this.entries = [];
        this.redoEntries = [];
        this.bytes = 0;
        this.lastAt = 0;
        this.lastOp = null;
    }

    get depth() {
        return this.entries.length;
    }

    /**
     * Push a gesture.
     *
     * Coalescing is the reason a vertex drag is one undo step rather than
     * ninety. It is time-boxed at 500 ms and broken by any other command type,
     * so a drag followed by a delete never merges into one entry — the user
     * would undo the delete and lose the drag with it.
     *
     * Only the **inverse of the first** command in a coalesced run is kept:
     * that inverse restores the state the gesture began from, which is what
     * undoing a gesture means. Keeping the last one would undo the final
     * pointer move and leave the other eighty-nine applied.
     */
    push(command, inverse, { coalesce = true, now = Date.now() } = {}) {
        const coalescing = coalesce
            && this.entries.length > 0
            && this.lastOp === command.op
            && command.coalescable === true
            && now - this.lastAt < this.coalesceMs;

        this.redoEntries = [];
        this.lastAt = now;
        this.lastOp = command.op;

        if (coalescing) {
            // Keep the original inverse; replace only the forward command, so
            // redo repeats the whole gesture rather than its first frame.
            this.entries[this.entries.length - 1].command = command;

            return;
        }

        const bytes = estimateBytes(inverse);

        this.entries.push({ command, inverse, bytes });
        this.bytes += bytes;

        this.trim();
    }

    pop() {
        const entry = this.entries.pop();

        if (!entry) {
            return null;
        }

        this.bytes -= entry.bytes;
        this.lastOp = null;

        return entry;
    }

    pushRedo(command, inverse) {
        this.redoEntries.push({ command, inverse, bytes: estimateBytes(inverse) });
    }

    popRedo() {
        return this.redoEntries.pop() ?? null;
    }

    /** Drop the oldest entries until both bounds hold. */
    trim() {
        while (this.entries.length > this.maxDepth || (this.bytes > this.maxBytes && this.entries.length > 1)) {
            const dropped = this.entries.shift();
            this.bytes -= dropped.bytes;
        }
    }

    clear() {
        this.entries = [];
        this.redoEntries = [];
        this.bytes = 0;
        this.lastOp = null;
    }
}
