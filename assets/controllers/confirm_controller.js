import { Controller } from '@hotwired/stimulus';

/* Demande une confirmation avant d'envoyer un formulaire (actions coûteuses ou irréversibles). */
export default class extends Controller {
    static values = { message: String };

    submit(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            return;
        }
        // Empêche le double clic : le bouton est désactivé après le premier envoi.
        this.element.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; });
    }
}
