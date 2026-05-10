import { Controller } from '@hotwired/stimulus';

/**
 * table controller — handles cursor-paginated next/prev clicks by fetching
 * the rows fragment endpoint and swapping the tbody, without touching the
 * chart or KPI strip. URL stays in sync via history.replaceState so the
 * query is shareable.
 *
 * v1 wires the data-action hooks; the actual paginator markup + endpoint
 * land in follow-up tasks.
 */
export default class extends Controller {
    static values = {
        rowsEndpoint: String,
    };

    async fetchPage(event) {
        if (!event?.target) return;
        event.preventDefault();
        const cursor = event.target.dataset.cursor;
        if (!cursor) return;
        const url = new URL(this.rowsEndpointValue, window.location.origin);
        url.searchParams.set('cursor', cursor);
        const tbody = this.element.querySelector('tbody');
        if (!tbody) return;
        tbody.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url.toString(), {
                headers: { Accept: 'text/html' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;
            const html = await response.text();
            tbody.innerHTML = html;
            const target = new URL(window.location.href);
            target.searchParams.set('cursor', cursor);
            window.history.replaceState({}, '', target.toString());
        } finally {
            tbody.removeAttribute('aria-busy');
        }
    }
}
