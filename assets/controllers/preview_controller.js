import { Controller } from '@hotwired/stimulus';

/*
 * Live-syncs form fields into the "Podgląd Twojego eBooka" panel. A source
 * field carries data-preview-field="title" (+ an action calling #update); each
 * preview element carries data-preview-target="out" data-preview-field="title"
 * and an optional data-preview-empty fallback. Selects show the option label.
 */
export default class extends Controller {
    static targets = ['out'];

    connect() {
        this.element
            .querySelectorAll('input[data-preview-field], select[data-preview-field], textarea[data-preview-field]')
            .forEach((el) => this.apply(el.dataset.previewField, this.read(el)));
    }

    update(e) {
        this.apply(e.target.dataset.previewField, this.read(e.target));
    }

    apply(field, value) {
        if (!field) return;
        this.outTargets.forEach((el) => {
            if (el.dataset.previewField === field) {
                el.textContent = '' !== value ? value : (el.dataset.previewEmpty ?? '');
            }
        });
    }

    read(el) {
        if ('SELECT' === el.tagName) {
            const opt = el.options[el.selectedIndex];
            return opt && '' !== opt.value ? opt.text : ''; // empty = placeholder → use fallback
        }
        return el.value.trim();
    }
}
