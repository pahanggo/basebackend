/**
 * Dragging nodes in the layer tree.
 *
 * **Pointer Events throughout**, so mouse, touch and stylus are one code path
 * rather than three — which is the whole reason the specification names them
 * (section 8). HTML5 drag-and-drop is deliberately not used: it does not fire
 * on touch, it cannot express "into" against "between", and its drag image is
 * not ours to style.
 *
 * Three drop targets with three affordances: an insertion line above a node,
 * an insertion line below it, and a highlight for dropping into a group. A
 * drop that cannot work — a group into its own descendant — is refused while
 * the pointer is still down, so the gesture never completes into a rejection.
 *
 * The rows are recycled, so nothing here holds a row node across a scroll. A
 * drag is described by the placement id it picked up and the row *index* the
 * pointer is currently over, both re-read from the list on every move.
 */

import { el } from '../lib/dom.js';
import { isGroup, resolveDrop, dropPosition } from './tree-model.js';
import { layerReorder } from '../store/commands/layer.js';
import { between } from '../lib/sort-key.js';

/** Pointer travel before a press becomes a drag, so a click is still a click. */
const THRESHOLD_PX = 4;

/** Distance from an edge at which the list starts scrolling itself. */
const AUTOSCROLL_EDGE_PX = 28;
const AUTOSCROLL_STEP_PX = 8;

/** Hover over a collapsed group before it opens to accept a drop. */
const AUTO_EXPAND_MS = 600;

export function attachTreeDrag(tree) {
    const indicator = el('div', { class: 'gis-tree-drop', hidden: true, 'aria-hidden': 'true' });

    tree.scroller.append(indicator);

    let drag = null;
    let scrollTimer = null;
    let expandTimer = null;

    const clearTimers = () => {
        window.clearInterval(scrollTimer);
        window.clearTimeout(expandTimer);
        scrollTimer = null;
        expandTimer = null;
    };

    const finishVisuals = () => {
        indicator.hidden = true;
        tree.scroller.classList.remove('is-dragging');

        for (const node of tree.list.pool) {
            node.classList.remove('is-drop-into', 'is-drop-invalid');
        }
    };

    tree.scroller.addEventListener('pointerdown', (event) => {
        const node = event.target.closest?.('.gis-tree-row');

        // The checkbox, the twisty and the menu are controls, not handles.
        if (!node || event.target.closest('button, input')) {
            return;
        }

        const row = tree.rowAt(node);

        // A sublayer is not a placement: it has no sort key, no parent and
        // nothing to reorder. It rides wherever its layer goes.
        if (!row || row.kind === 'class') {
            return;
        }

        drag = {
            pointerId: event.pointerId,
            placementId: row.placement.id,
            startX: event.clientX,
            startY: event.clientY,
            active: false,
            drop: null,
        };
    });

    tree.scroller.addEventListener('pointermove', (event) => {
        if (!drag || event.pointerId !== drag.pointerId) {
            return;
        }

        if (!drag.active) {
            const moved = Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY);

            if (moved < THRESHOLD_PX) {
                return;
            }

            drag.active = true;
            tree.scroller.setPointerCapture(event.pointerId);
            tree.scroller.classList.add('is-dragging');
            tree.closeMenu();
        }

        event.preventDefault();
        autoScroll(event.clientY);
        update(event.clientY);
    });

    const endDrag = (event) => {
        if (!drag || (event && event.pointerId !== drag.pointerId)) {
            return;
        }

        const pending = drag;

        drag = null;
        clearTimers();
        finishVisuals();

        if (!pending.active || !pending.drop) {
            return;
        }

        const placement = tree.store.state.placements[pending.placementId];

        if (!placement) {
            return;
        }

        // Multi-select moves the block. Each node writes its own row, in the
        // order they appear in the tree, so the block keeps its internal order
        // where it lands.
        const block = tree.selection.has(pending.placementId) && tree.selection.size > 1
            ? tree.rows.filter((row) => tree.selection.has(row.placement.id)).map((row) => row.placement)
            : [placement];

        const { parentId, after } = pending.drop;
        let sortKey = pending.drop.sortKey;

        for (const node of block) {
            tree.store.commit(layerReorder({
                id: node.id,
                version: node.version,
                parentId,
                sortKey,
            }));

            // Chained against the bound the first key was cut from, so the
            // block keeps its order without the key growing a character per
            // node — which is what appending a digit would have done.
            sortKey = between(sortKey, after);
        }

        tree.rebuild();
        tree.onChanged();
    };

    tree.scroller.addEventListener('pointerup', endDrag);
    tree.scroller.addEventListener('pointercancel', endDrag);

    /**
     * Recompute the drop target from the pointer's Y, and show it.
     *
     * Everything is re-read here — the row index, the row itself, whether the
     * drop is legal. The list may have scrolled under the pointer since the
     * last move, and the pooled nodes will have been refilled if it did.
     */
    function update(clientY) {
        const index = tree.list.indexAt(clientY);

        finishVisuals();

        if (index === -1) {
            drag.drop = null;

            return;
        }

        const row = tree.rows[index];

        // Nothing may be dropped onto a sublayer either — it is not a position
        // in the tree, so "above", "below" and "into" all mean nothing there.
        if (!row || row.kind === 'class') {
            drag.drop = null;

            return;
        }

        const group = isGroup(tree.store.state, row.placement);
        const position = dropPosition(tree.list.fractionAt(clientY), group);
        const resolved = resolveDrop(tree.store.state, drag.placementId, row.placement.id, position);
        const node = tree.list.pool.find((pooled) => Number(pooled.dataset.index) === index);

        drag.drop = resolved;

        if (!resolved) {
            node?.classList.add('is-drop-invalid');

            return;
        }

        if (position === 'into') {
            node?.classList.add('is-drop-into');
            scheduleExpand(row);

            return;
        }

        // The insertion line is positioned in list coordinates rather than
        // against the pooled node, which is a recycled element that may be
        // showing a different row by the next frame.
        const top = (index + (position === 'below' ? 1 : 0)) * tree.list.height;

        indicator.style.top = `${top}px`;
        indicator.style.marginLeft = `${row.depth * 16}px`;
        indicator.hidden = false;
    }

    /** A collapsed group opens after a hover, so a drop can reach inside it. */
    function scheduleExpand(row) {
        if (!tree.collapsed.has(row.placement.id) || expandTimer) {
            return;
        }

        expandTimer = window.setTimeout(() => {
            expandTimer = null;
            tree.collapsed.delete(row.placement.id);
            tree.rebuild();
        }, AUTO_EXPAND_MS);
    }

    /** Dragging near an edge scrolls the list, so a long tree is reachable. */
    function autoScroll(clientY) {
        const box = tree.scroller.getBoundingClientRect();
        const above = clientY - box.top;
        const below = box.bottom - clientY;
        const step = above < AUTOSCROLL_EDGE_PX
            ? -AUTOSCROLL_STEP_PX
            : (below < AUTOSCROLL_EDGE_PX ? AUTOSCROLL_STEP_PX : 0);

        if (step === 0) {
            window.clearInterval(scrollTimer);
            scrollTimer = null;

            return;
        }

        if (scrollTimer) {
            return;
        }

        scrollTimer = window.setInterval(() => {
            tree.scroller.scrollTop += step;
        }, 16);
    }

    return {
        destroy() {
            clearTimers();
            indicator.remove();
        },
    };
}
