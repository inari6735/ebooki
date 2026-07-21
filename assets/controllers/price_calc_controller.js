import { Controller } from '@hotwired/stimulus';

/*
 * Live revenue preview for the pricing step. Values are also rendered
 * server-side (progressive enhancement); this only refreshes them as the
 * price changes. Each output element declares its role + decimals:
 *   data-price-calc-target="out" data-price-calc-role="earn" data-decimals="2"
 * Roles: final | earn | commission | monthly | gross.
 */
export default class extends Controller {
    static targets = ['price', 'out'];
    static values = { share: { type: Number, default: 70 }, sales: { type: Number, default: 100 } };

    connect() {
        this.update();
    }

    update() {
        const price = parseFloat(String(this.priceTarget.value).replace(',', '.')) || 0;
        const author = (price * this.shareValue) / 100;
        const commission = price - author;

        const map = {
            final: price,
            earn: author * this.salesValue,
            commission: commission * this.salesValue,
            monthly: author * this.salesValue,
            gross: price * this.salesValue,
        };

        this.outTargets.forEach((el) => {
            const value = map[el.dataset.priceCalcRole];
            if (value === undefined) return;
            const decimals = parseInt(el.dataset.decimals ?? '2', 10);
            el.textContent = this.zl(value, decimals);
        });
    }

    zl(v, decimals) {
        return v.toLocaleString('pl-PL', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' zł';
    }
}
