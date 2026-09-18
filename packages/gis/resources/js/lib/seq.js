/**
 * The sequence half of the idempotency key, counted once per client.
 *
 * The server identifies a unit of work by `(clientId, seq)`. `clientId` is
 * issued per tab and stable for its life, so **every** seq that tab sends has
 * to come from here — creating a map and sending a command batch are both
 * units of work against the same key space.
 *
 * They did not always. The map browser and the outbound queue each counted from
 * one, so the first command after creating a map carried the pair the creation
 * had already used: the server recognised it as a replay and returned the
 * creation's response instead of doing the work. The map appeared, the layer
 * did not, and a reload fixed it — because a reload issues a new `clientId`.
 */
export class SeqCounter {
    constructor(start = 0) {
        this.value = start;
    }

    /** The next unused sequence number. */
    next() {
        this.value += 1;

        return this.value;
    }

    /**
     * Give the last one back, for a retry.
     *
     * A retry has to carry the SAME pair or the server cannot tell it from a
     * second, identical piece of work — which is the whole point of the key.
     * Safe only while nothing else has taken a number in between, which holds
     * because a queue retries before it sends anything new.
     */
    rollback() {
        this.value -= 1;
    }
}
