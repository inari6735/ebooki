import { Controller } from '@hotwired/stimulus';

/*
 * Free-form tag input. Chips are posted as `<name>[]` hidden inputs so the
 * server receives an array. Add with Enter/comma, remove via the chip's ×.
 */
export default class extends Controller {
    static targets = ['input', 'list'];
    static values = { name: String };

    add(e) {
        if (e.key !== 'Enter' && e.key !== ',') return;
        e.preventDefault();
        const value = this.inputTarget.value.trim().replace(/,+$/, '');
        this.inputTarget.value = '';
        if (value) this.append(value);
    }

    append(value) {
        const chip = document.createElement('span');
        chip.className =
            'inline-flex items-center gap-1.5 rounded-full bg-accent-50 px-3 py-1.5 text-sm font-medium text-accent-700';
        chip.innerHTML =
            `<input type="hidden" name="${this.nameValue}[]">` +
            `<span></span>` +
            `<button type="button" data-action="tags#remove" class="text-accent-600/60 hover:text-accent-700" aria-label="Usuń">&times;</button>`;
        chip.querySelector('input').value = value;
        chip.querySelector('span').textContent = value;
        this.listTarget.insertBefore(chip, this.inputTarget);
    }

    remove(e) {
        e.target.closest('span[class*="rounded-full"]').remove();
    }
}
