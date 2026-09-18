/**
 * The last N of anything, without growing.
 *
 * A plain array with `shift()` would be O(n) per push and would churn; this
 * overwrites in place, which matters because it sits on the commit path and a
 * vertex drag commits on every pointer move.
 */
export class RingBuffer {
    constructor(capacity = 200) {
        this.capacity = capacity;
        this.items = new Array(capacity);
        this.next = 0;
        this.size = 0;
    }

    push(item) {
        this.items[this.next] = item;
        this.next = (this.next + 1) % this.capacity;
        this.size = Math.min(this.size + 1, this.capacity);
    }

    /** Oldest first. */
    toArray() {
        const out = [];
        const start = (this.next - this.size + this.capacity) % this.capacity;

        for (let i = 0; i < this.size; i += 1) {
            out.push(this.items[(start + i) % this.capacity]);
        }

        return out;
    }
}
