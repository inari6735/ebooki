import { Controller } from '@hotwired/stimulus';

/*
 * Repeatable key:value rows (e.g. extra eBook details). Rows are posted as
 * parallel `detailKeys[]` / `detailValues[]` arrays; the server pairs them up.
 */
export default class extends Controller {
    static targets = ['list', 'template'];

    add() {
        const row = this.templateTarget.content.firstElementChild.cloneNode(true);
        this.listTarget.appendChild(row);
        row.querySelector('input')?.focus();
    }

    remove(e) {
        e.target.closest('[data-key-value-target="row"]')?.remove();
    }
}
