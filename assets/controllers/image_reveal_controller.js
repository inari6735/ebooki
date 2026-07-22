import { Controller } from '@hotwired/stimulus';

/*
 * Fades a cover image in once it has loaded, over the shimmer skeleton behind it.
 *
 * Progressive enhancement: the image is fully visible in the HTML, so without JS
 * it just shows normally. Only when this controller runs do we hide a not-yet-
 * loaded image and fade it in on `load`. Already-cached images are left visible
 * (no pointless flash), and we also reveal on `error` so a broken image never
 * stays stuck invisible. `loading="lazy"` covers below the fold fade in as you
 * scroll to them.
 */
export default class extends Controller {
    connect() {
        const img = this.element;

        // Already loaded (e.g. from cache) → nothing to animate.
        if (img.complete && img.naturalWidth > 0) {
            return;
        }

        img.classList.add('opacity-0');
        this.reveal = () => img.classList.remove('opacity-0');
        img.addEventListener('load', this.reveal, { once: true });
        img.addEventListener('error', this.reveal, { once: true });
    }

    disconnect() {
        if (this.reveal) {
            this.element.removeEventListener('load', this.reveal);
            this.element.removeEventListener('error', this.reveal);
        }
    }
}
