/**
 * Resolving a version conflict, rather than merely reporting one.
 *
 * S4 built the arithmetic and S5b made the queue pause and say so once. That is
 * the safe half: nothing is lost, but nothing moves either — and an optimistic
 * UI keeps showing every later change as applied, so the map looks right while
 * the queue silently holds the work. Until this panel, the only way out was a
 * reload, which threw the work away.
 *
 * **Most conflicts never reach here.** The server merges a stale command whose
 * fields nobody else touched and reports it as merged; what arrives is the
 * residue, where two sides wrote the same field (specification section 16).
 *
 * Three resolutions and only three. **Geometry is never merged** — not because
 * it is hard, but because nobody has decided what merging two edits to the same
 * ring should mean. One side wins and the user says which.
 */

import { el, clear } from '../lib/dom.js';
import { pairConflicts, resolve, KEEP_MINE, KEEP_THEIRS } from '../store/conflicts.js';

export class ConflictPanel {
    /**
     * @param {Object} options
     * @param {Object} options.strings translated labels
     * @param {Function} options.onResolved called with the commands to send
     *        and the server state to adopt
     */
    constructor({ strings, onResolved }) {
        this.strings = strings;
        this.onResolved = onResolved;
        this.element = null;
        this.pairs = [];
        this.choices = new Map();
    }

    /** Show the conflicts in a 409, paired with the commands that caused them. */
    open(problem, commands) {
        this.close();

        this.pairs = pairConflicts(problem, commands);
        this.choices = new Map();

        if (this.pairs.length === 0) {
            // A 409 with nothing to resolve. Rare, and the honest response is
            // to say the queue is stuck rather than show an empty dialog.
            this.onResolved?.({ send: [], adopt: [], resume: true });

            return;
        }

        const body = el('div', { class: 'gis-conflict-body' });

        this.pairs.forEach((pair, index) => body.append(this.row(pair, index)));

        this.element = el('div', { class: 'gis-conflict', role: 'dialog', 'aria-modal': 'true' }, [
            el('div', { class: 'gis-conflict-head' }, [
                el('strong', { text: this.strings.conflictTitle }),
                el('p', { class: 'gis-conflict-note', text: this.strings.conflictHelp }),
            ]),
            body,
            el('div', { class: 'gis-conflict-foot' }, [
                el('button', {
                    type: 'button',
                    class: 'btn btn-sm btn-light',
                    text: this.strings.keepAllTheirs,
                    onclick: () => this.applyAll(KEEP_THEIRS),
                }),
                el('button', {
                    type: 'button',
                    class: 'btn btn-sm btn-primary',
                    text: this.strings.applyResolution,
                    onclick: () => this.apply(),
                }),
            ]),
        ]);

        document.body.append(this.element);
        this.element.querySelector('input')?.focus();
    }

    /**
     * One conflict: what it is, who else touched it, and the choice.
     *
     * The other writer is named from the command log rather than from a column
     * on the row — the log already knows, and a `last_updated_by` column would
     * be a second answer that could disagree with the first.
     */
    row(pair, index) {
        const { conflict } = pair;
        const name = `gis-conflict-${index}`;

        const option = (value, label) => {
            const input = el('input', {
                type: 'radio',
                name,
                value,
                checked: value === KEEP_MINE,
            });

            input.addEventListener('change', () => this.choices.set(index, value));

            return el('label', { class: 'gis-conflict-choice' }, [input, el('span', { text: label })]);
        };

        this.choices.set(index, KEEP_MINE);

        return el('div', { class: 'gis-conflict-row' }, [
            el('div', { class: 'gis-conflict-what' }, [
                el('span', { class: 'gis-conflict-entity', text: `${conflict.entity} #${conflict.id}` }),
                el('span', {
                    class: 'gis-conflict-versions',
                    text: this.strings.conflictVersions
                        .replace(':yours', String(conflict.yourVersion))
                        .replace(':theirs', String(conflict.serverVersion)),
                }),
            ]),
            el('div', { class: 'gis-conflict-choices' }, [
                option(KEEP_MINE, this.strings.keepMine),
                option(KEEP_THEIRS, this.strings.keepTheirs),
            ]),
        ]);
    }

    applyAll(choice) {
        this.pairs.forEach((_, index) => this.choices.set(index, choice));
        this.apply();
    }

    /**
     * Turn the choices into commands to send and state to adopt.
     *
     * Everything goes in one resolution rather than one per conflict: the
     * queue is paused, and resuming it between two halves of a decision would
     * send the first and conflict again on the second.
     */
    apply() {
        const send = [];
        const adopt = [];

        this.pairs.forEach((pair, index) => {
            const result = resolve(pair, this.choices.get(index) ?? KEEP_MINE);

            send.push(...result.send);

            if (result.adopt) {
                adopt.push({ conflict: pair.conflict, server: result.adopt });
            }
        });

        this.close();
        this.onResolved?.({ send, adopt, resume: true });
    }

    close() {
        this.element?.remove();
        this.element = null;
    }
}
