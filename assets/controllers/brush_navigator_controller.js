import { Controller } from '@hotwired/stimulus';

/**
 * brush-navigator — listens for `explorer:time-range-selected` (dispatched
 * by chart_controller when the user finishes a brush selection on the
 * uPlot canvas) and rewrites the page URL with the new since/until query
 * parameters, then navigates. URL is the source of truth for query state,
 * so the resulting full reload re-hydrates every deferred Live Component
 * against the brushed window.
 *
 * Attached at the explorer page wrapper so a single instance covers the
 * whole page; debounce coalesces rapid drag adjustments into one nav.
 */
export default class extends Controller {
    static values = {
        debounceMs: { type: Number, default: 150 },
    };

    #pendingDetail = null;
    #pendingTimer = null;

    connect() {
        this.handleSelected = (event) => {
            this.#pendingDetail = event.detail;
            if (this.#pendingTimer) {
                clearTimeout(this.#pendingTimer);
            }
            this.#pendingTimer = setTimeout(() => {
                this.#applyPending();
            }, this.debounceMsValue);
        };
        this.element.addEventListener('explorer:time-range-selected', this.handleSelected);
    }

    disconnect() {
        if (this.#pendingTimer) {
            clearTimeout(this.#pendingTimer);
            this.#pendingTimer = null;
        }
        this.element.removeEventListener('explorer:time-range-selected', this.handleSelected);
    }

    #applyPending() {
        const detail = this.#pendingDetail;
        if (!detail || typeof detail.since !== 'string' || typeof detail.until !== 'string') {
            return;
        }
        const url = new URL(window.location.href);
        url.searchParams.set('since', detail.since);
        url.searchParams.set('until', detail.until);
        // Clear any cursor so we land on the first page of the new window.
        url.searchParams.delete('cursor');
        window.location.href = url.toString();
    }
}
