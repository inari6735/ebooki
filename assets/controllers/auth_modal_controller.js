import { Controller } from '@hotwired/stimulus';

/*
 * In-page login / registration modal used at the last step of the publish wizard.
 * When an anonymous author clicks "Opublikuj"/"Zapisz szkic" we intercept the
 * click, open this modal, and authenticate over AJAX — the wizard page (and all
 * the work put into it) is never left. The modal reuses the exact same shared
 * Auth:LoginForm / Auth:RegisterForm as the login/register pages. On success we
 * submit the matching finalize form, which the server completes as authenticated.
 */
export default class extends Controller {
    static targets = ['modal', 'loginPanel', 'registerPanel', 'loginTab', 'registerTab', 'loginError', 'registerError'];
    static values = { loginUrl: String, registerUrl: String };

    connect() {
        this.intent = 'publish';
        this.onKey = (event) => { if ('Escape' === event.key) this.close(); };
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    open(event) {
        event.preventDefault();
        this.intent = event.params.intent || 'publish';
        this.showLogin();
        this.clearErrors();
        this.modalTarget.classList.remove('hidden');
        this.modalTarget.classList.add('flex');
        document.addEventListener('keydown', this.onKey);
        this.loginPanelTarget.querySelector('input[type="email"]')?.focus();
    }

    close() {
        this.modalTarget.classList.add('hidden');
        this.modalTarget.classList.remove('flex');
        document.removeEventListener('keydown', this.onKey);
    }

    backdrop(event) {
        if (event.target === this.modalTarget) this.close();
    }

    showLogin() {
        this.loginPanelTarget.classList.remove('hidden');
        this.registerPanelTarget.classList.add('hidden');
        this.activate(this.loginTabTarget, this.registerTabTarget);
    }

    showRegister() {
        this.registerPanelTarget.classList.remove('hidden');
        this.loginPanelTarget.classList.add('hidden');
        this.activate(this.registerTabTarget, this.loginTabTarget);
    }

    async submitLogin(event) {
        event.preventDefault();
        this.loginErrorTarget.textContent = '';
        const response = await this.post(this.loginUrlValue, new FormData(event.target));
        if (response.ok) return this.finalize();
        this.loginErrorTarget.textContent = (await this.message(response)) || 'Nie udało się zalogować.';
    }

    async submitRegister(event) {
        event.preventDefault();
        this.registerErrorTarget.textContent = '';

        const form = event.target;
        const response = await this.post(this.registerUrlValue, new FormData(form));
        if (!response.ok) {
            this.registerErrorTarget.textContent = (await this.message(response)) || 'Nie udało się utworzyć konta.';
            return;
        }

        // Account created → log in automatically with the same credentials.
        const login = new FormData();
        login.append('email', this.field(form, 'registration[email]'));
        login.append('password', this.field(form, 'registration[plainPassword][first]'));
        login.append('_csrf_token', this.loginPanelTarget.querySelector('[name="_csrf_token"]')?.value ?? '');

        const loginResponse = await this.post(this.loginUrlValue, login);
        if (loginResponse.ok) return this.finalize();
        this.registerErrorTarget.textContent = 'Konto utworzone — zaloguj się, aby dokończyć.';
        this.showLogin();
    }

    finalize() {
        document.getElementById('draft' === this.intent ? 'draft-form' : 'publish-form').submit();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    post(url, body) {
        return fetch(url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
    }

    async message(response) {
        try {
            const data = await response.json();
            return data.error || (Array.isArray(data.errors) ? data.errors[0] : null);
        } catch (_) {
            return null;
        }
    }

    field(form, name) {
        return form.querySelector(`[name="${name}"]`)?.value ?? '';
    }

    clearErrors() {
        this.loginErrorTarget.textContent = '';
        this.registerErrorTarget.textContent = '';
    }

    activate(on, off) {
        on.classList.add('bg-white', 'text-accent-700', 'shadow-sm');
        on.classList.remove('text-zinc-500');
        off.classList.remove('bg-white', 'text-accent-700', 'shadow-sm');
        off.classList.add('text-zinc-500');
    }
}
