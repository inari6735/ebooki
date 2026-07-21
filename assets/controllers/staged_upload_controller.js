import { Controller } from '@hotwired/stimulus';

/*
 * Uploads files to the wizard's staging endpoints as they are added, with a live
 * progress bar and removal. Enforces the SAME rules as the server (size, allowed
 * formats, max count, one file per format, no duplicate file) so the user gets
 * instant, clear feedback; the server re-checks everything authoritatively.
 *
 * eBook files (which can be up to 200 MB) are uploaded in CHUNK_SIZE pieces so PHP
 * never receives one huge request — init → chunk×N (sequential, each retried) →
 * finalize, which reassembles the parts only if the transfer is complete. The
 * cover (≤5 MB) still goes up in a single POST.
 */
const CHUNK_SIZE = 8 * 1024 * 1024; // 8 MB — keeps post_max_size small (12 MB)
const CHUNK_RETRIES = 3;

export default class extends Controller {
    static targets = ['input', 'list', 'error'];
    static values = {
        uploadUrl: String, // cover: single POST
        initUrl: String, // file: begin chunked upload
        chunkUrl: String, // file: one chunk
        finalizeUrl: String, // file: assemble
        removeUrl: String,
        token: String,
        context: String, // staging workspace: '' = wizard, an eBook id = its edit session
        cover: Boolean,
        maxSizeMb: Number,
        maxCount: Number,
        accept: String, // comma-separated extensions
        formId: { type: String, default: 'wizard-form' }, // form whose submit to guard
    };

    // Every staging request carries the token + the workspace context.
    body(fields = {}) {
        const body = new FormData();
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        body.append('_token', this.tokenValue);
        body.append('ctx', this.contextValue || '');
        return body;
    }

    connect() {
        // Guard the step-1 "Dalej" button: you may not advance while a file (or
        // the cover) is still uploading — its session reference only exists once
        // the upload has finalised, so leaving early would drop it. The backend
        // enforces "at least one staged file" too; this is the instant, no-reload
        // feedback in front of it (the edit form is guarded the same way).
        this.form = document.getElementById(this.formIdValue);
        if (this.form) {
            this.onSubmit = (event) => this.guardSubmit(event);
            this.form.addEventListener('submit', this.onSubmit);
        }
    }

    disconnect() {
        if (this.form) this.form.removeEventListener('submit', this.onSubmit);
    }

    // A list item without a data-media-id is still uploading (markDone stamps it).
    guardSubmit(event) {
        if (!this.listTarget.querySelector(':scope > *:not([data-media-id])')) return;
        event.preventDefault();
        this.showError(
            this.coverValue
                ? 'Poczekaj, aż okładka zakończy wysyłanie.'
                : 'Poczekaj, aż pliki zakończą wysyłanie, zanim przejdziesz dalej.',
        );
    }

    open() {
        this.inputTarget.click();
    }

    change() {
        Array.from(this.inputTarget.files || []).forEach((file) => this.add(file));
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
        Array.from(event.dataTransfer.files || []).forEach((file) => this.add(file));
    }

    add(file) {
        const error = this.validate(file);
        if (error) {
            this.showError(error);
            return;
        }
        if (this.coverValue) {
            this.uploadCover(file);
        } else {
            this.uploadChunked(file);
        }
    }

    validate(file) {
        if (this.maxSizeMbValue && file.size > this.maxSizeMbValue * 1024 * 1024) {
            return `Plik „${file.name}" jest za duży (maks. ${this.maxSizeMbValue} MB).`;
        }
        if (!file.size) {
            return 'Nie udało się odczytać pliku — spróbuj ponownie.';
        }
        if (this.coverValue) return null; // cover: size only; a new one replaces the old

        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const allowed = (this.acceptValue || '').split(',').filter(Boolean);
        if (allowed.length && !allowed.includes(ext)) {
            return `Nieobsługiwany format „.${ext}". Dozwolone: ${allowed.join(', ').toUpperCase()}.`;
        }

        const items = Array.from(this.listTarget.children);
        if (this.maxCountValue && items.length >= this.maxCountValue) {
            return `Możesz dodać maksymalnie ${this.maxCountValue} plików.`;
        }
        if (items.some((el) => el.dataset.format === ext)) {
            return `Plik w formacie ${ext.toUpperCase()} został już dodany — każdy format można dodać tylko raz.`;
        }
        if (items.some((el) => el.dataset.fileKey === `${file.name}:${file.size}`)) {
            return 'Ten plik został już dodany.';
        }
        return null;
    }

    // ── Cover: single POST ─────────────────────────────────────────────────────

    uploadCover(file) {
        this.clearError();
        this.listTarget.replaceChildren();
        const item = this.buildCoverItem(file);
        this.listTarget.appendChild(item);
        this.listTarget.classList.remove('hidden');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', this.uploadUrlValue);
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) this.setProgress(item, Math.round((event.loaded / event.total) * 100));
        });
        xhr.addEventListener('load', () => {
            const payload = this.parse(xhr);
            if (xhr.status >= 200 && xhr.status < 300) {
                this.markDone(item, payload);
            } else {
                this.reject(item, payload.error || 'Nie udało się wgrać pliku.');
            }
        });
        xhr.addEventListener('error', () => this.reject(item, 'Błąd sieci podczas wysyłania.'));
        xhr.send(this.body({ file }));
    }

    // ── eBook file: chunked upload ─────────────────────────────────────────────

    async uploadChunked(file) {
        this.clearError();
        const item = this.buildItem(file);
        this.listTarget.appendChild(item);
        this.listTarget.classList.remove('hidden');

        try {
            const uploadId = await this.chunkInit(file);
            const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

            for (let index = 0; index < total; index++) {
                const blob = file.slice(index * CHUNK_SIZE, Math.min(file.size, (index + 1) * CHUNK_SIZE));
                await this.sendChunk(uploadId, index, blob, (loaded) => {
                    this.setProgress(item, Math.round(((index * CHUNK_SIZE + loaded) / file.size) * 100));
                });
            }

            const ref = await this.chunkFinalize(uploadId, total, file);
            this.markDone(item, ref);
        } catch (error) {
            this.reject(item, error.message || 'Nie udało się wgrać pliku.');
        }
    }

    async chunkInit(file) {
        const body = this.body({ name: file.name, size: file.size });
        const response = await fetch(this.initUrlValue, { method: 'POST', body });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || 'Nie udało się rozpocząć wysyłania.');
        return payload.uploadId;
    }

    // Sends one chunk, retrying transient failures a few times before giving up.
    sendChunk(uploadId, index, blob, onProgress, attempt = 1) {
        return new Promise((resolve, reject) => {
            const body = this.body({ uploadId, index, chunk: blob });

            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.chunkUrlValue);
            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) onProgress(event.loaded);
            });
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    onProgress(blob.size);
                    resolve();
                } else if (xhr.status >= 400 && xhr.status < 500) {
                    // A client error (bad token, chunk too big) won't fix itself.
                    reject(new Error(this.parse(xhr).error || 'Nie udało się wysłać fragmentu.'));
                } else {
                    this.retryChunk(uploadId, index, blob, onProgress, attempt, resolve, reject);
                }
            });
            xhr.addEventListener('error', () => this.retryChunk(uploadId, index, blob, onProgress, attempt, resolve, reject));
            xhr.send(body);
        });
    }

    retryChunk(uploadId, index, blob, onProgress, attempt, resolve, reject) {
        if (attempt >= CHUNK_RETRIES) {
            reject(new Error('Przesyłanie przerwane — sprawdź połączenie i spróbuj ponownie.'));
            return;
        }
        setTimeout(() => {
            this.sendChunk(uploadId, index, blob, onProgress, attempt + 1).then(resolve, reject);
        }, 500 * attempt);
    }

    async chunkFinalize(uploadId, total, file) {
        const body = this.body({ uploadId, total, name: file.name, size: file.size, mime: file.type || 'application/octet-stream' });
        const response = await fetch(this.finalizeUrlValue, { method: 'POST', body });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || 'Nie udało się zapisać pliku.');
        return payload;
    }

    remove(event) {
        const item = event.target.closest('[data-media-id]');
        if (!item) return;
        fetch(this.removeUrlValue.replace('__ID__', item.dataset.mediaId), { method: 'POST', body: this.body() })
            .then((response) => {
                if (response.ok) {
                    this.revokePreview(item);
                    item.remove();
                    if (!this.listTarget.children.length) this.listTarget.classList.add('hidden');
                }
            });
    }

    revokePreview(item) {
        if (item.dataset.objectUrl) URL.revokeObjectURL(item.dataset.objectUrl);
    }

    // ── DOM ──────────────────────────────────────────────────────────────────

    buildItem(file) {
        const item = document.createElement('div');
        item.className = 'flex items-center gap-3 rounded-xl border border-zinc-100 bg-white p-3 shadow-sm';
        if (!this.coverValue) {
            item.dataset.format = (file.name.split('.').pop() || '').toLowerCase();
            item.dataset.fileKey = `${file.name}:${file.size}`;
        }
        item.innerHTML = `
            <span data-thumb class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-accent-50 text-accent-600">
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
        item.querySelector('[data-name]').textContent = file.name;
        return item;
    }

    // The cover gets a real, visible thumbnail (portrait 2:3) shown INSTANTLY from
    // a local object URL — the reader sees their chosen image the moment they pick
    // it, before the upload even finishes.
    buildCoverItem(file) {
        const item = document.createElement('div');
        item.className = 'flex items-center gap-4 rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm';
        item.dataset.humanSize = this.humanSize(file.size);
        item.innerHTML = `
            <span data-thumb class="block w-16 shrink-0 overflow-hidden rounded-lg bg-accent-50 shadow ring-1 ring-zinc-100/60" style="aspect-ratio: 2 / 3;">
                <img data-preview alt="Podgląd okładki" class="h-full w-full object-cover">
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-accent-600">Podgląd okładki</p>
                <p class="mt-0.5 truncate text-sm font-medium text-zinc-900" data-name></p>
                <p class="mt-1 text-xs text-zinc-400" data-meta>Wysyłanie…</p>
                <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-zinc-100" data-bar-wrap>
                    <div class="h-full w-0 rounded-full bg-accent-600 transition-[width]" data-bar></div>
                </div>
            </div>
            <button type="button" data-action="staged-upload#remove" class="hidden shrink-0 text-zinc-400 hover:text-error-600" aria-label="Usuń">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" class="h-5 w-5"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>`;
        item.querySelector('[data-name]').textContent = file.name;
        const objectUrl = URL.createObjectURL(file);
        item.dataset.objectUrl = objectUrl;
        item.querySelector('[data-preview]').src = objectUrl;
        return item;
    }

    humanSize(bytes) {
        const mb = bytes / (1024 * 1024);
        return mb >= 1
            ? `${mb.toLocaleString('pl-PL', { maximumFractionDigits: 1 })} MB`
            : `${Math.round(bytes / 1024).toLocaleString('pl-PL')} KB`;
    }

    setProgress(item, percent) {
        const bar = item.querySelector('[data-bar]');
        if (bar) bar.style.width = `${Math.min(100, percent)}%`;
    }

    markDone(item, payload) {
        item.dataset.mediaId = payload.mediaId;
        item.querySelector('[data-bar-wrap]')?.classList.add('hidden');
        const meta = item.querySelector('[data-meta]');
        if (meta) {
            meta.textContent = this.coverValue
                ? (item.dataset.humanSize || '')
                : [payload.size, (payload.format || '').toUpperCase()].filter(Boolean).join(' · ');
        }
        item.querySelector('[data-action]').classList.remove('hidden');
        // The instant object-URL preview already shows the image; nothing to swap.
    }

    // The server rejected the upload — it was never staged, so drop the row and
    // surface the reason (a duplicate the client couldn't detect, etc.).
    reject(item, message) {
        this.revokePreview(item);
        item.remove();
        if (!this.listTarget.children.length) this.listTarget.classList.add('hidden');
        this.showError(message);
    }

    parse(xhr) {
        try {
            return JSON.parse(xhr.responseText);
        } catch (_) {
            return {};
        }
    }

    showError(message) {
        if (this.hasErrorTarget) this.errorTarget.textContent = message;
    }

    clearError() {
        if (this.hasErrorTarget) this.errorTarget.textContent = '';
    }
}
