import { Controller } from '@hotwired/stimulus';

/*
 * Uploads files to the wizard's staging endpoints as they are added, with a live
 * progress bar, and lets the user remove them. The server keeps the references in
 * the session; here we only mirror them in the UI. Used for both the (multiple)
 * eBook files and the (single) cover — `cover` mode replaces the current item and
 * shows a thumbnail from the returned preview URL.
 */
export default class extends Controller {
    static targets = ['input', 'list'];
    static values = { uploadUrl: String, removeUrl: String, token: String, cover: Boolean };

    open() {
        this.inputTarget.click();
    }

    change() {
        Array.from(this.inputTarget.files || []).forEach((file) => this.upload(file));
        this.inputTarget.value = '';
    }

    dragover(event) {
        event.preventDefault();
        this.element.classList.add('border-accent-500', 'bg-accent-50/40');
    }

    dragleave() {
        this.element.classList.remove('border-accent-500', 'bg-accent-50/40');
    }

    drop(event) {
        event.preventDefault();
        this.dragleave();
        Array.from(event.dataTransfer.files || []).forEach((file) => this.upload(file));
    }

    upload(file) {
        if (this.coverValue) this.listTarget.replaceChildren();
        const item = this.buildItem(file.name);
        this.listTarget.appendChild(item);
        this.listTarget.classList.remove('hidden');

        const body = new FormData();
        body.append('file', file);
        body.append('_token', this.tokenValue);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', this.uploadUrlValue);
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) this.setProgress(item, Math.round((event.loaded / event.total) * 100));
        });
        xhr.addEventListener('load', () => {
            let payload = {};
            try { payload = JSON.parse(xhr.responseText); } catch (_) { /* ignore */ }
            if (xhr.status >= 200 && xhr.status < 300) {
                this.markDone(item, payload);
            } else {
                this.markError(item, payload.error || 'Nie udało się wgrać pliku.');
            }
        });
        xhr.addEventListener('error', () => this.markError(item, 'Błąd sieci podczas wysyłania.'));
        xhr.send(body);
    }

    remove(event) {
        const item = event.target.closest('[data-media-id]');
        if (!item) return;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        fetch(this.removeUrlValue.replace('__ID__', item.dataset.mediaId), { method: 'POST', body })
            .then((response) => {
                if (response.ok) {
                    item.remove();
                    if (!this.listTarget.children.length) this.listTarget.classList.add('hidden');
                }
            });
    }

    // ── DOM ──────────────────────────────────────────────────────────────────

    buildItem(name) {
        const item = document.createElement('div');
        item.className = 'flex items-center gap-3 rounded-xl border border-zinc-100 bg-white p-3 shadow-sm';
        item.innerHTML = `
            <span data-thumb class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-accent-50 text-accent-600 overflow-hidden">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-zinc-900" data-name></p>
                <p class="mt-1 text-xs text-zinc-400" data-meta>Wysyłanie…</p>
                <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-zinc-100" data-bar-wrap>
                    <div class="h-full w-0 rounded-full bg-accent-600 transition-[width]" data-bar></div>
                </div>
            </div>
            <button type="button" data-action="staged-upload#remove" class="hidden shrink-0 text-zinc-400 hover:text-error-600" aria-label="Usuń">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" class="h-5 w-5"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>`;
        item.querySelector('[data-name]').textContent = name;
        return item;
    }

    setProgress(item, percent) {
        const bar = item.querySelector('[data-bar]');
        if (bar) bar.style.width = `${percent}%`;
    }

    markDone(item, payload) {
        item.dataset.mediaId = payload.mediaId;
        item.querySelector('[data-bar-wrap]').classList.add('hidden');
        item.querySelector('[data-meta]').textContent = [payload.size, (payload.format || '').toUpperCase()].filter(Boolean).join(' · ');
        item.querySelector('[data-action]').classList.remove('hidden');
        if (this.coverValue && payload.previewUrl) {
            const thumb = item.querySelector('[data-thumb]');
            thumb.innerHTML = '';
            const img = document.createElement('img');
            img.src = payload.previewUrl;
            img.alt = '';
            img.className = 'h-full w-full object-cover';
            thumb.appendChild(img);
        }
    }

    markError(item, message) {
        item.querySelector('[data-bar-wrap]').classList.add('hidden');
        const meta = item.querySelector('[data-meta]');
        meta.textContent = message;
        meta.classList.add('text-error-600');
        item.querySelector('[data-action]').classList.remove('hidden');
    }
}
