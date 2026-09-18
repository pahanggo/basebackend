/**
 * Reading the framed feature stream.
 *
 * The binary encoding is not one `GIS1` document but a sequence of them, each
 * carrying at most `stream_chunk` features, so the renderer can paint what has
 * arrived instead of waiting for the whole read. Framing is the smallest thing
 * that makes that possible over a byte stream: a kind and a length, so a reader
 * knows how much to buffer before it has anything to parse.
 *
 *     uint8   kind: 1 features, 2 trailer
 *     uint32  payload length, little-endian
 *     bytes   payload
 */

export const FRAME_FEATURES = 1;
export const FRAME_TRAILER = 2;

const HEADER_BYTES = 5;

/**
 * Frames, as they arrive.
 *
 * @param {ReadableStream<Uint8Array>} body
 * @returns {AsyncGenerator<{kind: number, payload: Uint8Array}>}
 */
export async function* readFrames(body) {
    const reader = body.getReader();
    const queue = new ByteQueue();

    try {
        for (;;) {
            if (!await queue.fill(reader, HEADER_BYTES)) {
                // A clean end between frames. The trailer should have ended the
                // loop already; arriving here means the response was cut short
                // after a whole frame, which the caller sees as a missing
                // trailer rather than as corrupt data.
                return;
            }

            const head = queue.take(HEADER_BYTES);
            const kind = head[0];
            const length = new DataView(head.buffer).getUint32(1, true);

            if (!await queue.fill(reader, length)) {
                throw new Error('Truncated feature stream');
            }

            yield { kind, payload: queue.take(length) };
        }
    } finally {
        // A consumer that aborts mid-stream leaves the reader locked otherwise,
        // and the next read on the same body throws.
        reader.releaseLock();
    }
}

/**
 * The bytes read so far, in the pieces the network delivered them in.
 *
 * `take` always copies into a fresh buffer rather than returning a view into a
 * network chunk. That is not a missed optimisation: a `GIS1` document is read
 * by pointing a `Float64Array` at an offset within its buffer, which requires
 * the buffer to start where the document starts. A view at an arbitrary offset
 * would fail alignment, and detaching a shared network chunk in a transfer
 * would take the following frame with it.
 */
class ByteQueue {
    constructor() {
        this.parts = [];
        this.size = 0;
        this.done = false;
    }

    /** Read until at least `wanted` bytes are held, or the body ends. */
    async fill(reader, wanted) {
        while (this.size < wanted && !this.done) {
            const { value, done } = await reader.read();

            if (done) {
                this.done = true;
                break;
            }

            this.parts.push(value);
            this.size += value.byteLength;
        }

        return this.size >= wanted;
    }

    /** @returns {Uint8Array} exactly `wanted` bytes, in a buffer of its own */
    take(wanted) {
        const out = new Uint8Array(wanted);
        let written = 0;

        while (written < wanted) {
            const part = this.parts[0];
            const slice = Math.min(part.byteLength, wanted - written);

            out.set(part.subarray(0, slice), written);
            written += slice;

            if (slice === part.byteLength) {
                this.parts.shift();
            } else {
                this.parts[0] = part.subarray(slice);
            }
        }

        this.size -= wanted;

        return out;
    }
}
