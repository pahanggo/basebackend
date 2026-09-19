/**
 * What the renderer is actually doing, on screen, behind a shortcut.
 *
 * **It ships in production behind a flag rather than only in development**,
 * because the reports that matter come from real deployments with real data
 * (specification §19). A budget met on a developer's machine over a fixture is
 * a claim about the fixture.
 *
 * Two sources, and they answer different questions:
 *
 * - **The renderer's own counters** say what the last frame cost: how many
 *   features were candidates, how many survived the cull, how many vertices
 *   went into the paths, and how long the build and the paint took.
 * - **`PerformanceObserver`** says what the whole page did, including the work
 *   this module knows nothing about. A long task is 50 ms of blocked main
 *   thread, which is the thing a user feels — and the frame counter alone
 *   cannot see it, because a frame that never ran reports nothing.
 *
 * Frame rate is sampled rather than averaged over the session: the number that
 * matters is what is happening now, and a mean over five minutes of idle hides
 * the two seconds of stutter that prompted somebody to open this.
 */

import { el } from '../lib/dom.js';

/** A task over this many milliseconds blocks the main thread visibly. */
const LONG_TASK_MS = 50;

/** How many recent frames the rate is averaged over. */
const WINDOW = 30;

/**
 * Frames per second over a window of timestamps, or null before there are two.
 *
 * Exported and pure because it is the part that can be wrong without looking
 * wrong: a rate computed over the wrong span reads as a plausible number. The
 * overlay that draws it needs a browser; this does not.
 *
 * Sampled over a window rather than averaged over the session, because the
 * number that matters is what is happening NOW — a mean over five minutes of
 * idle hides the two seconds of stutter that prompted somebody to open it.
 */
export function frameRate(frames) {
    if (frames.length < 2) {
        return null;
    }

    const span = frames[frames.length - 1] - frames[0];

    // n timestamps bound n-1 intervals, and using n here would overstate the
    // rate by a thirtieth at the default window — small, and wrong.
    return span > 0 ? ((frames.length - 1) * 1000) / span : null;
}

/** The worst gap between consecutive frames, in milliseconds. */
export function worstGap(frames) {
    let worst = 0;

    for (let i = 1; i < frames.length; i++) {
        worst = Math.max(worst, frames[i] - frames[i - 1]);
    }

    return worst;
}

/**
 * Whether a figure is outside its section 19 budget.
 *
 * Kept in one place so the overlay's highlighting and any later gate read the
 * same numbers. A budget that is written twice is a budget that disagrees with
 * itself the first time one of them moves.
 */
export const BUDGETS = {
    fps: (value) => value !== null && value < 55,
    worstFrame: (value) => value > 33,
    drawn: (value) => value > 20_000,
    candidates: (value) => value > 250_000,
    buildMs: (value) => value > 16,
    paintMs: (value) => value > 16,
    heapBytes: (value) => value > 250 * 1048576,
};

export class PerformanceOverlay {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container the map shell
     * @param {Object} options.renderer the source of the draw counters
     * @param {Function} options.context () => what the user was doing
     */
    constructor({ container, renderer, context = () => ({}) }) {
        this.renderer = renderer;
        this.context = context;
        this.frames = [];
        this.longTasks = 0;
        this.worstTask = 0;
        this.visible = false;
        this.frame = null;

        this.element = el('div', {
            class: 'gis-perf',
            role: 'status',
            'aria-live': 'off',
            hidden: true,
        });

        container.append(this.element);

        this.onKey = (event) => {
            // Ctrl+Shift+P, which no browser claims and nothing else here uses.
            if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'p') {
                event.preventDefault();
                this.toggle();
            }
        };

        window.addEventListener('keydown', this.onKey);
        this.watchLongTasks();
    }

    /**
     * Record long tasks whether or not the overlay is open.
     *
     * The point of logging them is to catch the ones nobody was watching for.
     * Starting the observer on open would only ever see the tasks that happen
     * after somebody already suspected a problem.
     */
    watchLongTasks() {
        if (typeof PerformanceObserver === 'undefined') {
            return;
        }

        try {
            this.observer = new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (entry.duration < LONG_TASK_MS) {
                        continue;
                    }

                    this.longTasks += 1;
                    this.worstTask = Math.max(this.worstTask, entry.duration);

                    // Logged with what the user was doing, because "a 240 ms
                    // task" is not actionable and "a 240 ms task while the
                    // polygon tool was active with 40,000 features drawn" is.
                    console.warn('gis: long task', Math.round(entry.duration), 'ms', this.context());
                }
            });

            this.observer.observe({ entryTypes: ['longtask'] });
        } catch {
            // Not supported everywhere, and its absence is not worth a
            // message: the counters below still work.
        }
    }

    toggle() {
        this.visible = !this.visible;
        this.element.hidden = !this.visible;

        if (this.visible) {
            this.frames = [];
            this.tick();
        } else if (this.frame !== null) {
            window.cancelAnimationFrame(this.frame);
            this.frame = null;
        }
    }

    tick() {
        const now = performance.now();

        this.frames.push(now);

        if (this.frames.length > WINDOW) {
            this.frames.shift();
        }

        this.render();
        this.frame = window.requestAnimationFrame(() => this.tick());
    }

    rate() {
        return frameRate(this.frames);
    }

    worstFrame() {
        return worstGap(this.frames);
    }

    render() {
        const stats = this.renderer.stats?.() ?? {};
        const rate = this.rate();
        const worst = this.worstFrame();
        const heap = performance.memory?.usedJSHeapSize;

        const rows = [
            ['fps', rate === null ? '—' : rate.toFixed(0), BUDGETS.fps(rate)],
            ['worst frame', `${worst.toFixed(1)} ms`, BUDGETS.worstFrame(worst)],
            ['drawn', String(stats.drawn ?? 0), BUDGETS.drawn(stats.drawn ?? 0)],
            ['candidates', String(stats.candidates ?? 0), BUDGETS.candidates(stats.candidates ?? 0)],
            ['vertices', String(stats.vertices ?? 0), false],
            ['build', `${(stats.buildMs ?? 0).toFixed(1)} ms`, BUDGETS.buildMs(stats.buildMs ?? 0)],
            ['paint', `${(stats.paintMs ?? 0).toFixed(2)} ms`, BUDGETS.paintMs(stats.paintMs ?? 0)],
            ['long tasks', `${this.longTasks}${this.worstTask ? ` (${Math.round(this.worstTask)} ms)` : ''}`, this.longTasks > 0],
        ];

        if (heap) {
            rows.push(['heap', `${(heap / 1048576).toFixed(0)} MB`, BUDGETS.heapBytes(heap)]);
        }

        this.element.textContent = '';

        for (const [label, value, over] of rows) {
            this.element.append(el('div', { class: `gis-perf-row${over ? ' is-over' : ''}` }, [
                el('span', { text: label }),
                el('span', { class: 'gis-perf-value', text: value }),
            ]));
        }
    }

    destroy() {
        window.removeEventListener('keydown', this.onKey);
        this.observer?.disconnect();

        if (this.frame !== null) {
            window.cancelAnimationFrame(this.frame);
        }

        this.element.remove();
    }
}
