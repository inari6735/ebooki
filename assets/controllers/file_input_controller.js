import { Controller } from '@hotwired/stimulus';

/* Styled file dropzone: click/drag to pick a file, shows its name + size. */
export default class extends Controller {
    static targets = ['input', 'idle', 'filled', 'name', 'size'];

    open() {
        this.inputTarget.click();
    }

    change() {
        const f = this.inputTarget.files[0];
        if (!f) return;
        if (this.hasNameTarget) this.nameTarget.textContent = f.name;
        if (this.hasSizeTarget) this.sizeTarget.textContent = this.human(f.size);
        this.idleTargets.forEach((el) => el.classList.add('hidden'));
        this.filledTargets.forEach((el) => el.classList.remove('hidden'));
    }

    clear(e) {
        e.preventDefault();
        e.stopPropagation();
        this.inputTarget.value = '';
        this.idleTargets.forEach((el) => el.classList.remove('hidden'));
        this.filledTargets.forEach((el) => el.classList.add('hidden'));
    }

    dragover(e) {
        e.preventDefault();
        this.element.classList.add('ring-2', 'ring-accent-600');
    }

    dragleave() {
        this.element.classList.remove('ring-2', 'ring-accent-600');
    }

    drop(e) {
        e.preventDefault();
        this.element.classList.remove('ring-2', 'ring-accent-600');
        this.inputTarget.files = e.dataTransfer.files;
        this.change();
    }

    human(b) {
        const mb = b / 1048576;
        return mb >= 1 ? mb.toFixed(1).replace('.', ',') + ' MB' : Math.round(b / 1024) + ' KB';
    }
}
