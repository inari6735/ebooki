import { Controller } from '@hotwired/stimulus';

/*
 * Live price preview + pricing-mode toggles.
 *   - "free" and "pwyw" (pay-what-you-want) are mutually exclusive.
 *   - either one disables/dims the price & promo controls.
 *   - preview shows "Za darmo" / "Dobrowolna wpłata" / the effective price;
 *     a valid promo (0 < promo < base) reveals the struck original + a badge.
 * Values are also rendered server-side (progressive enhancement).
 */
export default class extends Controller {
    static targets = ['price', 'promo', 'effective', 'original', 'discount', 'free', 'pwyw', 'dim', 'error'];

    connect() {
        this.update();

        // Guard "Dalej": block advancing (with a clear reason) on an invalid price
        // — non-numeric input (e.g. "10e"), a price ≤ 0, or a promo above the base
        // price. Mirrors the server-side pricing rules for instant feedback.
        this.form = document.getElementById('wizard-form');
        if (this.form) {
            this.onSubmit = (event) => {
                const message = this.priceError();
                if (message) {
                    event.preventDefault();
                    this.showError(message);
                }
            };
            this.form.addEventListener('submit', this.onSubmit);
        }
    }

    disconnect() {
        if (this.form) this.form.removeEventListener('submit', this.onSubmit);
    }

    // Returns a Polish error message when the pricing is invalid, or null when OK.
    priceError() {
        // "Za darmo" / "Zapłać ile chcesz" hide the price entirely — nothing to check.
        if ((this.hasFreeTarget && this.freeTarget.checked) || (this.hasPwywTarget && this.pwywTarget.checked)) {
            return null;
        }

        if (this.isBadInput(this.priceTarget) || !this.isPlainNumber(this.priceTarget.value)) {
            return 'Podaj poprawną cenę, np. 29,99 (bez liter i innych znaków).';
        }
        if (this.num(this.priceTarget) <= 0) {
            return 'Cena eBooka musi być większa od zera.';
        }

        if (this.hasPromoTarget && '' !== String(this.promoTarget.value).trim()) {
            if (this.isBadInput(this.promoTarget) || !this.isPlainNumber(this.promoTarget.value)) {
                return 'Podaj poprawną cenę promocyjną, np. 19,99 (bez liter i innych znaków).';
            }
            const promo = this.num(this.promoTarget);
            if (promo < 0) {
                return 'Cena promocyjna nie może być ujemna.';
            }
            if (promo > this.num(this.priceTarget)) {
                return 'Cena promocyjna nie może być wyższa od ceny eBooka.';
            }
        }

        return null;
    }

    // A plain decimal: digits with an optional comma/dot fraction. Rejects the
    // scientific notation ("1e5") and stray letters an <input type=number> allows.
    isPlainNumber(value) {
        const v = String(value).trim();
        return '' !== v && /^\d+([.,]\d+)?$/.test(v);
    }

    isBadInput(el) {
        return !!(el.validity && el.validity.badInput);
    }

    showError(message) {
        if (!this.hasErrorTarget) return;
        this.errorTarget.textContent = message;
        this.errorTarget.classList.remove('hidden');
        this.errorTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    clearError() {
        if (this.hasErrorTarget) this.errorTarget.classList.add('hidden');
    }

    update(e) {
        this.clearError();

        // Mutual exclusivity: turning one on turns the other off.
        if (e && this.hasFreeTarget && this.hasPwywTarget) {
            if (e.target === this.freeTarget && this.freeTarget.checked) this.pwywTarget.checked = false;
            if (e.target === this.pwywTarget && this.pwywTarget.checked) this.freeTarget.checked = false;
        }

        const free = this.hasFreeTarget && this.freeTarget.checked;
        const pwyw = this.hasPwywTarget && this.pwywTarget.checked;
        const noPrice = free || pwyw;

        [this.priceTarget, this.hasPromoTarget ? this.promoTarget : null].forEach((el) => {
            if (el) el.disabled = noPrice;
        });
        this.dimTargets.forEach((el) => {
            el.classList.toggle('opacity-50', noPrice);
            el.classList.toggle('pointer-events-none', noPrice);
        });

        if (free) return this.effective('Za darmo', 'text-success-600', true);
        if (pwyw) return this.effective('Dobrowolna wpłata', 'text-accent-600', true);

        const base = this.num(this.priceTarget);
        const promo = this.hasPromoTarget ? this.num(this.promoTarget) : 0;
        const discounted = promo > 0 && promo < base;

        this.effective(this.zl(discounted ? promo : base), 'text-zinc-900', false);
        if (this.hasOriginalTarget) {
            this.originalTarget.textContent = this.zl(base);
            this.originalTarget.classList.toggle('hidden', !discounted);
        }
        if (this.hasDiscountTarget) {
            const pct = base > 0 ? Math.round(((base - promo) / base) * 100) : 0;
            this.discountTarget.textContent = `-${pct}%`;
            this.discountTarget.classList.toggle('hidden', !discounted);
        }
    }

    effective(text, colorClass, hideExtras) {
        if (this.hasEffectiveTarget) {
            this.effectiveTarget.textContent = text;
            this.effectiveTarget.classList.remove('text-zinc-900', 'text-success-600', 'text-accent-600');
            this.effectiveTarget.classList.add(colorClass);
        }
        if (hideExtras) {
            if (this.hasOriginalTarget) this.originalTarget.classList.add('hidden');
            if (this.hasDiscountTarget) this.discountTarget.classList.add('hidden');
        }
    }

    num(el) {
        return parseFloat(String(el.value).replace(',', '.')) || 0;
    }

    zl(v) {
        return v.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł';
    }
}
