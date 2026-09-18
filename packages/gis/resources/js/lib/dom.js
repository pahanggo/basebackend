/**
 * Building DOM without markup strings.
 *
 * Feature properties, layer names and map names are user-supplied, and a layer
 * imported from a shapefile is as untrusted as anything a user types. They
 * reach the DOM through `textContent` and nowhere else — the markup-setting
 * accessors are forbidden in any code path touching this data, and a grep
 * assertion in the test suite enforces it (specification section 20).
 *
 * This helper exists so that rule costs nothing to follow. The grep is
 * deliberately blunt, so even naming those accessors in a comment trips it:
 * that is the trade, and weakening the pattern to allow prose would be paying
 * the wrong side of it.
 */

/**
 * @param {string} tag
 * @param {Object} [attrs] class, dataset and ordinary attributes
 * @param {Array<Node|string>|string} [children] strings become text nodes
 */
export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (key === 'class') {
            node.className = value;
        } else if (key === 'dataset') {
            Object.assign(node.dataset, value);
        } else if (key === 'text') {
            node.textContent = String(value);
        } else if (key.startsWith('on') && typeof value === 'function') {
            node.addEventListener(key.slice(2).toLowerCase(), value);
        } else {
            node.setAttribute(key, value === true ? '' : String(value));
        }
    }

    for (const child of Array.isArray(children) ? children : [children]) {
        if (child === null || child === undefined || child === false) {
            continue;
        }

        node.append(typeof child === 'string' ? document.createTextNode(child) : child);
    }

    return node;
}

/** Empty a node without touching markup. */
export function clear(node) {
    while (node.firstChild) {
        node.removeChild(node.firstChild);
    }
}

/** A relative time with the absolute one on hover, as the map listing wants. */
export function relativeTime(iso) {
    if (!iso) {
        return '';
    }

    const then = new Date(iso);
    const seconds = Math.round((Date.now() - then.getTime()) / 1000);

    const units = [
        [60, 'second'],
        [3600, 'minute'],
        [86400, 'hour'],
        [2592000, 'day'],
        [31536000, 'month'],
        [Infinity, 'year'],
    ];

    let previous = 1;

    for (const [limit, unit] of units) {
        if (seconds < limit) {
            const value = Math.max(1, Math.floor(seconds / previous));

            return `${value} ${unit}${value === 1 ? '' : 's'} ago`;
        }

        previous = limit;
    }

    return then.toISOString();
}
