import { Controller } from '@hotwired/stimulus';

/*
 * Generic show/hide toggle (mobile menu, dropdowns). Behavior only (rule 5),
 * Turbo-safe. Toggles the `hidden` class on the panel target.
 */
export default class extends Controller {
    static targets = ['panel'];

    toggle() {
        this.panelTarget.classList.toggle('hidden');
    }

    close() {
        this.panelTarget.classList.add('hidden');
    }
}
