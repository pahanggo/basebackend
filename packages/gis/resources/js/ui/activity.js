/**
 * "Fetching features…", while a read is in flight.
 *
 * Two things make this more than a `hidden` toggle.
 *
 * **It counts rather than flags.** There is one feed per visible vector layer
 * and they read concurrently, so the indicator has to stay up until the last
 * one finishes, not the first.
 *
 * **It waits before appearing.** A warm read lands in well under a second, and
 * an indicator that flashes up and vanishes reads as a glitch rather than as
 * progress. Nothing is shown unless the read is still running after the delay,
 * so the common case is silent and only a slow one is announced.
 *
 * The label is rendered into the page by Blade, already translated; this only
 * shows and hides it.
 */
export class Activity {
    /**
     * @param {HTMLElement|null} node the indicator, or null where the page has none
     * @param {{delayMs?: number}} [options]
     */
    constructor(node, { delayMs = 150 } = {}) {
        this.node = node;
        this.delayMs = delayMs;
        this.running = 0;
        this.timer = null;
    }

    /** One more read started. */
    start() {
        this.running++;

        if (this.node === null || this.timer !== null || !this.node.hidden) {
            return;
        }

        this.timer = setTimeout(() => {
            this.timer = null;

            // Re-checked: the read may have finished inside the delay.
            if (this.running > 0) {
                this.node.hidden = false;
            }
        }, this.delayMs);
    }

    /** One read finished, whether it succeeded, failed or was aborted. */
    stop() {
        this.running = Math.max(0, this.running - 1);

        if (this.running > 0) {
            return;
        }

        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        if (this.node !== null) {
            this.node.hidden = true;
        }
    }
}
