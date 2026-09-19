/**
 * A fixed-height row recycler.
 *
 * Deliberately general: it knows about a row count, a row height and two
 * callbacks, and nothing about trees, layers or attributes. The layer tree is
 * its first caller and the attribute table (S9) is its second, so anything
 * tree-shaped that leaks in here has to come back out later.
 *
 * **Rows are recycled, not rebuilt.** A scroll reuses the DOM nodes already in
 * the pool and asks the caller to refill them, so scrolling a 2,000-node tree
 * allocates nothing and touches only the rows that actually changed. Building
 * a row per frame is what makes a long list stutter, and it is the entire
 * reason this file exists rather than a `map()` over the data.
 *
 * Height is fixed rather than measured. Measuring means a layout read per row
 * per frame, and the tree has no reason to vary: it is one line of text and a
 * few controls. Coarse pointers get a taller row because a 32 px target is not
 * reliably hittable with a thumb (specification section 17).
 */

import { el } from '../lib/dom.js';

export const ROW_HEIGHT = 32;
export const COARSE_ROW_HEIGHT = 40;

/** Rows rendered beyond each edge of the viewport, so a fast scroll stays ahead. */
const OVERSCAN = 6;

export function rowHeight() {
    return window.matchMedia?.('(pointer: coarse)').matches ? COARSE_ROW_HEIGHT : ROW_HEIGHT;
}

export class VirtualList {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container the scrolling element
     * @param {Function} options.createRow  () => HTMLElement, called once per pooled row
     * @param {Function} options.renderRow  (node, index) => void, called on every recycle
     * @param {number} [options.height] row height in pixels
     * @param {string} [options.role] ARIA role for the inner list element
     */
    constructor({ container, createRow, renderRow, height = rowHeight(), role = null }) {
        this.container = container;
        this.createRow = createRow;
        this.renderRow = renderRow;
        this.height = height;
        this.count = 0;
        this.pool = [];
        this.firstRendered = -1;

        // A spacer sized to the full list gives the scrollbar its true length
        // without the rows existing. The viewport holds only the pool, offset
        // into place — one transform per scroll rather than one per row.
        this.spacer = el('div', { class: 'gis-vlist-spacer', 'aria-hidden': 'true' });
        this.viewport = el('div', { class: 'gis-vlist-viewport', role: role || false });

        this.container.classList.add('gis-vlist');
        this.container.append(this.spacer, this.viewport);

        this.onScroll = () => this.schedule();
        this.container.addEventListener('scroll', this.onScroll, { passive: true });

        this.frame = null;
        this.observer = new ResizeObserver(() => this.schedule());
        this.observer.observe(this.container);
    }

    /** @param {number} count total rows, virtual */
    setCount(count) {
        this.count = Math.max(0, count);
        this.spacer.style.height = `${this.count * this.height}px`;

        // The window may not have moved, but what is in it has.
        this.firstRendered = -1;
        this.render();
    }

    /** Re-run `renderRow` over the rows currently on screen. */
    refresh() {
        this.firstRendered = -1;
        this.render();
    }

    schedule() {
        if (this.frame !== null) {
            return;
        }

        this.frame = window.requestAnimationFrame(() => {
            this.frame = null;
            this.render();
        });
    }

    render() {
        const viewportRows = Math.ceil(this.container.clientHeight / this.height);
        const first = Math.max(0, Math.floor(this.container.scrollTop / this.height) - OVERSCAN);
        const visible = Math.min(this.count - first, viewportRows + OVERSCAN * 2);

        if (visible <= 0) {
            this.trimPool(0);
            this.firstRendered = first;

            return;
        }

        this.growPool(visible);
        this.trimPool(visible);

        // Unchanged window: the caller asked for a refresh or the scroll ended
        // where it started, and there is nothing to refill.
        if (first === this.firstRendered) {
            return;
        }

        this.viewport.style.transform = `translateY(${first * this.height}px)`;

        for (let i = 0; i < visible; i += 1) {
            this.renderRow(this.pool[i], first + i);
        }

        this.firstRendered = first;
    }

    growPool(size) {
        while (this.pool.length < size) {
            const node = this.createRow();

            node.style.height = `${this.height}px`;
            this.pool.push(node);
            this.viewport.append(node);
        }
    }

    trimPool(size) {
        while (this.pool.length > size) {
            this.pool.pop().remove();
        }
    }

    /** Scroll a row into view, doing nothing when it already is. */
    scrollTo(index) {
        const top = index * this.height;
        const bottom = top + this.height;
        const viewTop = this.container.scrollTop;
        const viewBottom = viewTop + this.container.clientHeight;

        if (top < viewTop) {
            this.container.scrollTop = top;
        } else if (bottom > viewBottom) {
            this.container.scrollTop = bottom - this.container.clientHeight;
        }
    }

    /** The row index at a client Y coordinate, or -1 outside the list. */
    indexAt(clientY) {
        const box = this.container.getBoundingClientRect();
        const index = Math.floor((clientY - box.top + this.container.scrollTop) / this.height);

        return index >= 0 && index < this.count ? index : -1;
    }

    /** How far into its row a client Y coordinate falls, 0 at the top edge, 1 at the bottom. */
    fractionAt(clientY) {
        const box = this.container.getBoundingClientRect();
        const offset = (clientY - box.top + this.container.scrollTop) % this.height;

        return offset / this.height;
    }

    destroy() {
        this.container.removeEventListener('scroll', this.onScroll);
        this.observer.disconnect();
        this.trimPool(0);
        this.spacer.remove();
        this.viewport.remove();
    }
}
