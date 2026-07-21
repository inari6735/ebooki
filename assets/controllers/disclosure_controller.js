import { Controller } from '@hotwired/stimulus';

/*
 * Generic show/hide toggle (mobile menu, dropdowns). Behavior only, Turbo-safe.
 * Toggles the `hidden` class on the panel target and dismisses itself on an
 * outside click or the Escape key — the way dropdowns are expected to behave.
 * Nesting is supported: each element carrying data-controller="disclosure" scopes
 * its own panel target and its own outside-click boundary (this.element).
 */
export default class extends Controller {
    static targets = ['panel'];

    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) this.close();
        };
        this.onKeydown = (event) => {
            if ('Escape' === event.key) this.close();
        };
        document.addEventListener('click', this.onOutside);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
        document.removeEventListener('keydown', this.onKeydown);
    }

    toggle() {
        this.panelTarget.classList.toggle('hidden');
    }

    close() {
        this.panelTarget.classList.add('hidden');
    }
}
