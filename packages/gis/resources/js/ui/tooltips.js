/**
 * Bootstrap tooltips over the editor's chrome.
 *
 * **Delegated, one per container.** The layer tree recycles its rows, so a
 * tooltip bound to a row node would belong to whichever row that node showed
 * when it was bound, and would describe a different layer after the first
 * scroll. Bootstrap's `selector` option binds to the container and resolves the
 * target at hover time, which is the only form that survives recycling.
 *
 * Two things then have to be handled by hand, and both were found by use:
 *
 * - **`trigger` is `hover`, never `hover focus`.** A button keeps focus after
 *   a click, so with `focus` in the trigger list the tooltip stays on screen
 *   over the menu the click just opened, and only a click elsewhere dismisses
 *   it.
 * - **A recycled or scrolled-away row never fires `mouseleave`.** The pointer
 *   did not move; the element under it was refilled or removed. Bootstrap has
 *   no way to know, so the tooltip is left behind. Scrolling, pressing and
 *   losing the window all hide every open tooltip explicitly, and any node
 *   orphaned in `body` anyway is swept.
 *
 * **Every tooltip hides itself after a few seconds.** A hint that has been
 * read is clutter, and on a touch screen a tap leaves one open with no hover
 * to end it — the pointer never leaves, because there is no pointer.
 *
 * `container: 'body'` because the sidebar and the panel both scroll: a tooltip
 * rendered inside them is clipped by their own overflow.
 *
 * The application already bundles Bootstrap 4 and jQuery. If either is absent
 * this does nothing and the browser's native `title` tooltip is what the user
 * gets — slower and plainer, but never nothing and never stuck.
 */

/**
 * What the delegation binds to.
 *
 * **`[data-original-title]` is not optional here.** Bootstrap takes the `title`
 * off an element the first time it shows a tooltip for it and keeps the text in
 * `data-original-title` — so an element it has already handled no longer
 * matches `[title]`. With `[title]` alone the delegated handler matched once
 * and never again: the first hover worked, the tooltip then had no `mouseleave`
 * handler to hide it, and every later hover did nothing at all. That is the
 * whole of "the tooltip does not go away".
 */
const SELECTOR = '[title]:not([title=""]), [data-original-title]:not([data-original-title=""])';

/** How long a tooltip stays up once shown. */
const LIFETIME_MS = 5000;

export function enableTooltips(root, placement = 'bottom') {
    const $ = window.$;

    if (!root || !$ || typeof $.fn?.tooltip !== 'function') {
        return;
    }

    $(root).tooltip({
        selector: SELECTOR,
        placement,
        trigger: 'hover',
        container: 'body',
        boundary: 'window',
        delay: { show: 350, hide: 0 },
    });

    const hideAll = () => {
        for (const node of root.querySelectorAll(SELECTOR)) {
            if ($(node).data('bs.tooltip')) {
                $(node).tooltip('hide');
            }
        }

        // Whatever Bootstrap could not hide because its trigger is no longer
        // in the document — a pooled row the recycler dropped mid-hover.
        for (const orphan of document.querySelectorAll('body > .tooltip')) {
            orphan.remove();
        }
    };

    // Bootstrap fires these on the trigger, and with delegation they bubble to
    // the root — so one pair of handlers covers every tooltip, including the
    // ones on rows that did not exist when this ran.
    $(root).on('shown.bs.tooltip', SELECTOR, function () {
        const trigger = this;

        window.clearTimeout($(trigger).data('gis.tooltip.timer'));

        $(trigger).data('gis.tooltip.timer', window.setTimeout(() => {
            if ($(trigger).data('bs.tooltip')) {
                $(trigger).tooltip('hide');
            }
        }, LIFETIME_MS));
    });

    $(root).on('hidden.bs.tooltip', SELECTOR, function () {
        window.clearTimeout($(this).data('gis.tooltip.timer'));
    });

    $(root).on('pointerdown', SELECTOR, hideAll);

    // Capturing, because the tree scroller and the panel body scroll rather
    // than the root, and `scroll` does not bubble.
    root.addEventListener('scroll', hideAll, true);
    window.addEventListener('blur', hideAll);

    return hideAll;
}
