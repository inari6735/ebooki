import { Controller } from '@hotwired/stimulus';

/*
 * Toggles a password field between hidden and visible, swapping the eye icon.
 * Behavior only (rule 5) — content is server-rendered. Turbo-safe: Stimulus
 * re-binds on every navigation, no document-level state.
 */
export default class extends Controller {
    static targets = ['input', 'showIcon', 'hideIcon'];

    toggle() {
        const revealing = this.inputTarget.type === 'password';
        this.inputTarget.type = revealing ? 'text' : 'password';
        this.showIconTarget.classList.toggle('hidden', revealing);
        this.hideIconTarget.classList.toggle('hidden', !revealing);
    }
}
