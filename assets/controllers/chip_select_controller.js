import { Controller } from '@hotwired/stimulus';

/*
 * Toggle-select chips (e.g. genres), up to `max`. Selected chips carry a
 * hidden `<name>[]` input so the server receives the chosen values.
 */
export default class extends Controller {
    static targets = ['chip'];
    static values = { name: String, max: { type: Number, default: 3 } };

    toggle(e) {
        const chip = e.currentTarget;
        const selected = chip.dataset.selected === 'true';

        if (!selected && this.selectedCount() >= this.maxValue) return;

        chip.dataset.selected = selected ? 'false' : 'true';
        this.style(chip, !selected);

        if (selected) {
            chip.querySelector('input[type="hidden"]')?.remove();
        } else {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `${this.nameValue}[]`;
            input.value = chip.dataset.value;
            chip.appendChild(input);
        }
    }

    selectedCount() {
        return this.chipTargets.filter((c) => c.dataset.selected === 'true').length;
    }

    style(chip, on) {
        chip.classList.toggle('border-accent-600', on);
        chip.classList.toggle('bg-accent-50', on);
        chip.classList.toggle('text-accent-700', on);
        chip.classList.toggle('border-zinc-200', !on);
        chip.classList.toggle('text-zinc-600', !on);
    }
}
