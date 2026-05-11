import { Controller } from '@hotwired/stimulus';

/**
 * minimap controller — keeps a viewport rectangle in sync with the main
 * waterfall tree's horizontal scroll, and drags-to-scroll the tree from
 * the minimap.
 *
 * Pure DOM + CSS; no canvas. One rAF coalesces tree.scroll events so
 * rapid drags stay smooth. Click-elsewhere recenters the viewport on
 * the click position.
 */
export default class extends Controller {
    static targets = ['canvas', 'viewport'];
    static values = {
        treeSelector: String,
    };

    connect() {
        this.tree = document.querySelector(this.treeSelectorValue);
        if (!this.tree) return;

        this._rafPending = false;
        this._onTreeScroll = this._onTreeScroll.bind(this);
        this._onViewportMouseDown = this._onViewportMouseDown.bind(this);
        this._onCanvasClick = this._onCanvasClick.bind(this);

        this.tree.addEventListener('scroll', this._onTreeScroll, { passive: true });
        this.viewportTarget.addEventListener('mousedown', this._onViewportMouseDown);
        this.canvasTarget.addEventListener('click', this._onCanvasClick);

        // Initial sync once layout settles.
        requestAnimationFrame(() => this._syncViewport());
    }

    disconnect() {
        if (this.tree) {
            this.tree.removeEventListener('scroll', this._onTreeScroll);
        }
        this.viewportTarget.removeEventListener('mousedown', this._onViewportMouseDown);
        this.canvasTarget.removeEventListener('click', this._onCanvasClick);
    }

    _onTreeScroll() {
        if (this._rafPending) return;
        this._rafPending = true;
        requestAnimationFrame(() => {
            this._rafPending = false;
            this._syncViewport();
        });
    }

    _syncViewport() {
        if (!this.tree) return;
        const scrollWidth = this.tree.scrollWidth || 1;
        const clientWidth = this.tree.clientWidth || 1;
        const scrollLeft = this.tree.scrollLeft || 0;
        const leftPct = (scrollLeft / scrollWidth) * 100;
        const widthPct = Math.min(100, (clientWidth / scrollWidth) * 100);
        this.viewportTarget.style.left = `${leftPct}%`;
        this.viewportTarget.style.width = `${widthPct}%`;
    }

    _onViewportMouseDown(event) {
        event.preventDefault();
        const canvasRect = this.canvasTarget.getBoundingClientRect();
        const viewportRect = this.viewportTarget.getBoundingClientRect();
        const grabOffset = event.clientX - viewportRect.left;

        const onMove = (e) => {
            const x = e.clientX - canvasRect.left - grabOffset;
            this._scrollTreeToCanvasX(x);
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    _onCanvasClick(event) {
        // Click outside the viewport recenters it on the click position.
        if (event.target === this.viewportTarget) return;
        const canvasRect = this.canvasTarget.getBoundingClientRect();
        const viewportRect = this.viewportTarget.getBoundingClientRect();
        const x = event.clientX - canvasRect.left - (viewportRect.width / 2);
        this._scrollTreeToCanvasX(x);
    }

    _scrollTreeToCanvasX(canvasX) {
        if (!this.tree) return;
        const canvasWidth = this.canvasTarget.clientWidth || 1;
        const ratio = Math.max(0, Math.min(1, canvasX / canvasWidth));
        const scrollWidth = this.tree.scrollWidth || 0;
        const clientWidth = this.tree.clientWidth || 0;
        const maxScroll = Math.max(0, scrollWidth - clientWidth);
        this.tree.scrollLeft = ratio * maxScroll;
    }
}
