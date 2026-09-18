/**
 * A Bootstrap 4 modal, built from DOM rather than markup.
 *
 * The application already bundles Bootstrap 4 and jQuery, so the modal is the
 * one the rest of the admin uses — not a bespoke dialog with its own focus
 * trap, its own backdrop and its own set of bugs.
 */

import { el, clear } from '../lib/dom.js';

export class Modal {
    /**
     * @param {Object} options
     * @param {string} options.id
     * @param {string} options.title
     * @param {string} [options.size] modal-lg by default
     */
    constructor({ id, title, size = 'modal-lg' }) {
        this.body = el('div', { class: 'modal-body' });
        this.footer = el('div', { class: 'modal-footer' });
        this.titleNode = el('h5', { class: 'modal-title', text: title });

        this.root = el('div', {
            class: 'modal fade',
            id,
            tabindex: '-1',
            role: 'dialog',
            'aria-labelledby': `${id}-title`,
            'aria-hidden': 'true',
        }, [
            el('div', { class: `modal-dialog ${size}`, role: 'document' }, [
                el('div', { class: 'modal-content' }, [
                    el('div', { class: 'modal-header' }, [
                        this.titleNode,
                        el('button', {
                            type: 'button',
                            class: 'close',
                            'data-dismiss': 'modal',
                            'aria-label': 'Close',
                        }, [el('span', { 'aria-hidden': 'true', text: '×' })]),
                    ]),
                    this.body,
                    this.footer,
                ]),
            ]),
        ]);

        this.titleNode.id = `${id}-title`;

        document.body.append(this.root);
    }

    setBody(...nodes) {
        clear(this.body);
        this.body.append(...nodes);
    }

    setFooter(...nodes) {
        clear(this.footer);
        this.footer.append(...nodes);
    }

    show() {
        // `backdrop: 'static'` is deliberately not set: this modal opens
        // automatically when the editor has no map, and a dialog the user
        // cannot dismiss is a trap rather than a prompt.
        window.$(this.root).modal('show');
    }

    hide() {
        window.$(this.root).modal('hide');
    }

    onHidden(handler) {
        window.$(this.root).on('hidden.bs.modal', handler);
    }
}
