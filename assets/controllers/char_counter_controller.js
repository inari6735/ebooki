import { Controller } from '@hotwired/stimulus';

/* Live "used / max" character counter for an input or textarea. */
export default class extends Controller {
    static targets = ['field', 'count'];
    static values = { max: Number };

    connect() {
        this.update();
    }

    update() {
        this.countTarget.textContent = `${this.fieldTarget.value.length} / ${this.maxValue}`;
    }
}
