/**
 * The outbound command queue.
 *
 * Commands enter after commit and leave on a 400 ms debounce, or immediately
 * for structural ones — deleting a layer should not sit in a queue while the
 * user watches it disappear from the tree.
 *
 *   Local -> Queued -> InFlight -> Confirmed
 *                        |  \
 *                        |   `-> Conflict -> (user resolves) -> Queued
 *                        `-> network error -> Queued, with backoff
 *
 * The UI is optimistic: state has already changed locally and this reconciles.
 * That is only safe because the endpoint is atomic and idempotent — a batch
 * either applied whole or not at all, and a retry after a timeout replays the
 * original response rather than applying twice (specification section 16).
 */

import { SeqCounter } from '../lib/seq.js';

export const DEBOUNCE_MS = 400;
export const MAX_BATCH = 500;

/** Ops flushed immediately rather than debounced. */
export const STRUCTURAL = new Set([
    'layer.create',
    'layer.delete',
    'layer.group',
    'layer.ungroup',
    'layer.removeFromMap',
    'layer.share',
    'layer.reorder',
]);

export class SyncQueue {
    /**
     * @param {Object} options
     * @param {string} options.apiBase
     * @param {number} options.mapId
     * @param {string} options.clientId stable for this browser tab
     * @param {string} options.csrfToken
     * @param {Function} [options.onConflict] receives the 409 problem document
     * @param {Function} [options.onApplied] receives the applied list
     * @param {Function} [options.onError]
     */
    constructor({
        apiBase,
        mapId,
        clientId,
        csrfToken,
        onConflict = null,
        onApplied = null,
        onError = null,
        fetchImpl = null,
        debounceMs = DEBOUNCE_MS,
        seq = null,
    }) {
        this.apiBase = apiBase;
        this.mapId = mapId;
        this.clientId = clientId;
        this.csrfToken = csrfToken;
        this.onConflict = onConflict;
        this.onApplied = onApplied;
        this.onError = onError;
        this.fetch = fetchImpl || ((...args) => fetch(...args));
        this.debounceMs = debounceMs;

        this.queue = [];
        this.inFlight = null;
        this.paused = false;
        this.timer = null;

        // Shared with everything else that spends this client's key space — a
        // queue that counted on its own would collide with the map creation
        // that preceded it. Its own counter only when standalone, as in tests.
        this.seq = seq ?? new SeqCounter();
        this.mapVersion = 0;
        this.attempts = 0;
    }

    enqueue(command) {
        this.queue.push(command);

        if (this.paused) {
            return;
        }

        if (STRUCTURAL.has(command.op) || this.queue.length >= MAX_BATCH) {
            this.flush();

            return;
        }

        this.schedule();
    }

    schedule() {
        if (this.timer !== null) {
            return;
        }

        this.timer = setTimeout(() => {
            this.timer = null;
            this.flush();
        }, this.debounceMs);
    }

    /**
     * Wait until everything queued has been confirmed by the server.
     *
     * **`flush()` is not enough on its own**, and the difference is a bug you
     * only see occasionally: `flush()` returns immediately when a batch is
     * already on the wire, so a caller that awaited it and then re-read the
     * map could read a server that had not seen its command yet. The new layer
     * was created, the read raced it, and the tree came back without it —
     * until the next reload.
     *
     * Polled rather than promise-chained because a batch may be retried with
     * backoff while this waits, so there is no single promise to hold.
     *
     * @returns {Promise<boolean>} false if the queue did not drain in time
     */
    async drain(timeoutMs = 15000) {
        const deadline = Date.now() + timeoutMs;

        while (Date.now() < deadline) {
            if (!this.inFlight && this.queue.length === 0) {
                return true;
            }

            if (!this.inFlight && !this.paused) {
                await this.flush();

                continue;
            }

            await new Promise((resolve) => setTimeout(resolve, 25));
        }

        return false;
    }

    /** Stop sending. The queue keeps accepting; nothing leaves until resumed. */
    pause() {
        this.paused = true;

        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }
    }

    resume() {
        this.paused = false;

        if (this.queue.length > 0) {
            this.schedule();
        }
    }

    async flush() {
        if (this.paused || this.inFlight || this.queue.length === 0) {
            return null;
        }

        const batch = this.queue.splice(0, MAX_BATCH);

        // The sequence is assigned per batch and not incremented on retry: a
        // retry has to carry the SAME (clientId, seq) or the server cannot tell
        // it from a second, identical piece of work. That pair is the whole
        // idempotency mechanism.
        const seq = this.seq.next();

        const envelope = {
            clientId: this.clientId,
            seq,
            mapVersion: this.mapVersion,
            commands: batch.map((command) => command.serialize()),
        };

        this.inFlight = { envelope, commands: batch };

        try {
            return await this.send();
        } finally {
            this.inFlight = null;
        }
    }

    async send() {
        const { envelope, commands } = this.inFlight;

        let response;

        try {
            response = await this.fetch(`${this.apiBase}/maps/${this.mapId}/commands`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify(envelope),
            });
        } catch (error) {
            return this.retryLater(commands, error);
        }

        if (response.status === 409) {
            const problem = await response.json();

            // Pause rather than drop: the commands are still the user's work,
            // and the conflict panel resolves them into a retry.
            this.pause();
            this.inFlight = null;
            this.queue.unshift(...commands);
            this.onConflict?.(problem, commands);

            return problem;
        }

        if (!response.ok) {
            const problem = await response.json().catch(() => null);

            // A 4xx other than 409 is not retryable: sending the same batch
            // again produces the same refusal. The commands are dropped and the
            // caller rolls them back locally.
            if (response.status < 500) {
                this.onError?.(problem, commands);

                return problem;
            }

            return this.retryLater(commands, problem);
        }

        const body = await response.json();

        this.attempts = 0;
        this.mapVersion = body.mapVersion;
        this.onApplied?.(body, commands);

        if (this.queue.length > 0) {
            this.schedule();
        }

        return body;
    }

    /**
     * Requeue at the front with exponential backoff.
     *
     * Front, not back: commands are ordered and a later one may depend on this
     * one having applied — a `feature.update` behind its own `feature.create`
     * would address a `tempId` the server never saw.
     */
    retryLater(commands, error) {
        this.attempts += 1;
        this.queue.unshift(...commands);

        // The seq is rolled back with them, so the retry carries the pair the
        // server already knows about.
        this.seq.rollback();

        const delay = Math.min(30000, this.debounceMs * 2 ** this.attempts);

        this.timer = setTimeout(() => {
            this.timer = null;
            this.flush();
        }, delay);

        this.onError?.(error, commands, { retrying: true, delay });

        return null;
    }

    /** Queue shape for a bug report. */
    describe() {
        return {
            pending: this.queue.map((command) => command.op),
            inFlight: this.inFlight?.envelope?.commands?.map((command) => command.op) ?? [],
            paused: this.paused,
            seq: this.seq.value,
            mapVersion: this.mapVersion,
        };
    }
}
