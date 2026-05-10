import { Controller } from '@hotwired/stimulus';

/**
 * chart controller — owns the canvas and the brush-select interaction.
 *
 * v1: fetches the chart endpoint on connect, instantiates uPlot, registers
 *     a setSelect hook that dispatches `explorer:time-range-selected`.
 *
 *     The QueryForm component listens for this event and rewrites the URL,
 *     which triggers a full server round-trip — chart + KPIs + table all
 *     re-render against the new window. URL is the source of truth.
 */
export default class extends Controller {
    static values = {
        endpoint: String,
        emptyMessage: String,
    };

    async connect() {
        const target = this.element.querySelector('canvas');
        if (!target) return;

        try {
            const response = await fetch(this.endpointValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                this.#renderEmpty(this.emptyMessageValue || 'Failed to load chart');
                return;
            }
            const payload = await response.json();
            if (!payload?.series?.length) {
                this.#renderEmpty(this.emptyMessageValue || 'No data in this window');
                return;
            }

            const { default: uPlot } = await import('uplot');
            const opts = {
                width: target.clientWidth || target.parentElement.clientWidth,
                height: 240,
                scales: { x: { time: true } },
                series: [{ label: 'time' }, ...payload.series.map((s) => ({ label: s.label, stroke: 'currentColor' }))],
                hooks: {
                    setSelect: [
                        (u) => {
                            if (u.select.width <= 0) return;
                            const since = new Date(u.posToVal(u.select.left, 'x') * 1000).toISOString();
                            const until = new Date(u.posToVal(u.select.left + u.select.width, 'x') * 1000).toISOString();
                            this.element.dispatchEvent(new CustomEvent('explorer:time-range-selected', {
                                detail: { since, until },
                                bubbles: true,
                            }));
                        },
                    ],
                },
            };
            new uPlot(opts, [payload.x ?? [], ...payload.series.map((s) => s.values)], target);
        } catch (e) {
            this.#renderEmpty('Failed to load chart');
        }
    }

    #renderEmpty(message) {
        this.element.innerHTML = '';
        const span = document.createElement('span');
        span.textContent = message;
        span.style.color = '#999';
        span.style.fontSize = '0.85rem';
        this.element.appendChild(span);
    }
}
