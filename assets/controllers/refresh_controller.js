import { Controller } from '@hotwired/stimulus';

/*
 * Rafraîchit la page quand l'état du roman change (étape terminée, nouveau chapitre…).
 * Interroge le serveur toutes les quelques secondes uniquement pendant qu'une étape tourne.
 */
export default class extends Controller {
    static values = { url: String, fingerprint: String, active: Boolean, interval: { type: Number, default: 3000 } };

    connect() {
        if (this.activeValue) {
            this.timer = setInterval(() => this.check(), this.intervalValue);
        }
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async check() {
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const state = await response.json();
            if (state.fingerprint !== this.fingerprintValue) {
                window.location.reload();
            }
        } catch (e) {
            // Serveur momentanément indisponible : on réessaiera au prochain tour.
        }
    }
}
