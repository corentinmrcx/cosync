/**
 * Choix des destinataires d'un kit de dotation.
 *
 * Un seul type de destinataire à la fois : les panneaux masqués sont des `<fieldset>`
 * désactivés, donc leurs valeurs ne partent pas au serveur même si elles ont été
 * choisies lors d'un passage précédent.
 *
 * Le compte est tenu par type : seul le panneau visible peut émettre, `type` suffit donc
 * à savoir de qui vient l'événement.
 *
 * @param {string} typeInitial valeur de `cible_type` au chargement
 */
export function dotationCibles(typeInitial) {
    return {
        type: typeInitial,
        parType: {},

        majSelection(nombre) {
            this.parType[this.type] = nombre;
        },

        get coches() {
            return this.parType[this.type] || 0;
        },

        /** La cible par défaut ne désigne personne : elle n'a rien à choisir. */
        get peutValider() {
            return this.type === 'default' || this.coches > 0;
        },

        get libelleBouton() {
            return this.coches > 1 ? `Attribuer à ${this.coches} destinataires` : 'Attribuer';
        },
    };
}
