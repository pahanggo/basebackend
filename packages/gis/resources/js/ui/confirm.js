/**
 * Confirmations and notices, without blocking the page.
 *
 * `window.confirm` stops the event loop: no repaint, no pending fetch
 * resolving, no map redrawing behind the dialog. On a phone it is a system
 * sheet with the origin in it. And it cannot be styled or laid out, so a
 * destructive action gets the same plain box as a routine one.
 *
 * The admin already bundles SweetAlert (the `sweetalert` package, v2 — not
 * `sweetalert2`, which is a different API on a similar name), so this is the
 * dialog the rest of the application uses rather than a second one.
 *
 * **Both helpers fall back to the native dialog when SweetAlert is absent.** A
 * missing script must not turn a delete confirmation into a silent delete.
 */

/**
 * @param {Object} options
 * @param {string} options.title
 * @param {string} [options.text]
 * @param {string} options.confirmLabel
 * @param {string} options.cancelLabel
 * @param {boolean} [options.dangerous] red confirm button
 * @returns {Promise<boolean>}
 */
export async function confirmAction({ title, text = '', confirmLabel, cancelLabel, dangerous = true }) {
    const swal = window.swal;

    if (typeof swal !== 'function') {
        return window.confirm(text ? `${title}\n\n${text}` : title);
    }

    const answer = await swal({
        title,
        text,
        icon: dangerous ? 'warning' : 'info',
        buttons: {
            cancel: { text: cancelLabel, value: null, visible: true },
            confirm: { text: confirmLabel, value: true, className: dangerous ? 'btn-danger' : '' },
        },
        dangerMode: dangerous,
    });

    // SweetAlert resolves to `null` when dismissed, and to the button's
    // `value` when taken — so anything but an explicit `true` is a refusal.
    return answer === true;
}

/**
 * A notice with nothing to decide.
 *
 * Takes a bare string as well as an options object, because eight call sites
 * pass one — `notify(strings.drawNeedsLayer)` — and destructuring a string
 * yields an undefined title and an empty dialog. Every one of those was a
 * message the user needed and did not get, and none of them errored.
 */
export async function notify(options) {
    const { title, text = '', icon = 'error' } = typeof options === 'string'
        ? { title: options }
        : (options ?? {});

    const swal = window.swal;

    if (typeof swal !== 'function') {
        window.alert(text ? `${title}\n\n${text}` : title);

        return;
    }

    await swal({ title, text, icon });
}
