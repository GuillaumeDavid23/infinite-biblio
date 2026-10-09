import { Controller } from '@hotwired/stimulus';

/* Désactive le bouton après le premier envoi pour éviter les doubles commandes. */
export default class extends Controller {
    submit() {
        setTimeout(() => {
            this.element.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; b.textContent = 'Envoi…'; });
        }, 0);
    }
}
