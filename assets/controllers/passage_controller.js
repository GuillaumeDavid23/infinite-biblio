import { Controller } from '@hotwired/stimulus';

/* Copie le texte sélectionné dans le chapitre vers le champ « passage à corriger ». */
export default class extends Controller {
    static targets = ['field'];

    useSelection(event) {
        event.preventDefault();
        const text = window.getSelection().toString().trim();
        if (text === '') {
            window.alert('Sélectionnez d\'abord un passage dans le texte du chapitre, puis cliquez à nouveau.');
            return;
        }
        this.fieldTarget.value = text;
        this.fieldTarget.focus();
    }
}
