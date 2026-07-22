import { Controller } from '@hotwired/stimulus';

/*
 * Marks the sticky header as "scrolled" once the page moves past a small
 * threshold, so CSS (.site-header[data-scrolled="true"]) can switch it from its
 * light/transparent top state to a darker, frosted background that stays readable
 * over the content it overlaps. Passive listener + rAF so scrolling stays smooth.
 */
export default class extends Controller {
    connect() {
        this.ticking = false;
        this.onScroll = () => {
            if (this.ticking) return;
            this.ticking = true;
            requestAnimationFrame(() => {
                this.element.dataset.scrolled = window.scrollY > 24 ? 'true' : 'false';
                this.ticking = false;
            });
        };
        window.addEventListener('scroll', this.onScroll, { passive: true });
        this.onScroll();
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
    }
}
