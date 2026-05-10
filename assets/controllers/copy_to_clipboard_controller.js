import { Controller } from '@hotwired/stimulus';

/*
 * Copies a value-bearing element's value (or text) to the clipboard and
 * gives the operator visible confirmation. Used on the show-plaintext-once
 * partial — each copy keeps the secret out of the surrounding URL/history.
 */
export default class extends Controller {
    static targets = ['source'];

    async copy(event) {
        const button = event.currentTarget;
        const value = this.hasSourceTarget ? this.sourceTarget.value : '';
        if ('' === value) return;

        try {
            await navigator.clipboard.writeText(value);
        } catch (e) {
            // Fallback for browsers / contexts without clipboard API.
            this.sourceTarget.select();
            document.execCommand('copy');
        }

        const previous = button.textContent;
        button.textContent = 'Copied';
        button.disabled = true;
        setTimeout(() => {
            button.textContent = previous;
            button.disabled = false;
        }, 1500);
    }
}
