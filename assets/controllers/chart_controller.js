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
        try {
            const response = await fetch(this.endpointValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                this.#renderMessage('Failed to load chart', 'Reload the page or narrow the time window.');
                return;
            }
            const payload = await response.json();
            if (!payload?.series?.length) {
                this.#renderMessage(
                    this.emptyMessageValue || 'No data in this window',
                    'Try widening the time range or removing filters.',
                );
                return;
            }

            // Clear the loading placeholder and drop the flex-centering so
            // uPlot's chart can occupy the full container width. uPlot
            // appends a `<div class="uplot">` with its own canvases — the
            // initial `<canvas>` placeholder in the template was a no-op
            // (a canvas can't host child elements visibly).
            this.element.innerHTML = '';
            this.element.style.display = 'block';
            this.element.style.padding = '0';
            this.element.style.alignItems = 'unset';
            this.element.style.justifyContent = 'unset';

            const { default: uPlot } = await import('uplot');
            const opts = {
                width: this.element.clientWidth || this.element.parentElement.clientWidth,
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
            new uPlot(opts, [payload.x ?? [], ...payload.series.map((s) => s.values)], this.element);
        } catch (e) {
            this.#renderMessage('Failed to load chart', 'Reload the page or narrow the time window.');
        }
    }

    #renderMessage(message, hint) {
        // Reuse the same placeholder shell the template seeds with so the
        // empty state matches the loading state visually. The wrapping
        // .chart div keeps its 16rem min-height — no layout jump and the
        // empty state is impossible to miss.
        this.element.innerHTML = '';
        this.element.style.display = 'flex';
        const wrap = document.createElement('div');
        wrap.className = 'chart__placeholder';
        const icon = document.createElement('span');
        icon.className = 'chart__placeholder-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '∅';
        const line = document.createElement('span');
        line.textContent = message;
        wrap.appendChild(icon);
        wrap.appendChild(line);
        if (hint) {
            const hintEl = document.createElement('span');
            hintEl.className = 'chart__placeholder-hint';
            hintEl.textContent = hint;
            wrap.appendChild(hintEl);
        }
        this.element.appendChild(wrap);
    }
}
