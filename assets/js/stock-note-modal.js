/**
 * Modale de lecture et d'écriture d'une note de stock, ouverte depuis le tableau de gestion.
 *
 * Une note tient parfois en trois lignes : l'afficher dans la cellule déformait le tableau.
 * Le tableau ne montre donc qu'un bouton, et la note vit ici. La même modale sert la note
 * d'un article et celle d'une de ses tailles — seule l'URL de soumission change, et c'est
 * la ligne qui la fournit au clic.
 */
export function stockNoteModal() {
    return {
        ouverte: false,
        titre: '',
        sousTitre: '',
        action: '',
        token: '',
        taille: null,
        note: '',
        lecture: true,

        ouvrir(donnees) {
            this.titre = donnees.titre;
            this.sousTitre = donnees.sousTitre ?? '';
            this.action = donnees.action;
            this.token = donnees.token;
            this.taille = donnees.taille ?? null;
            this.note = donnees.note ?? '';
            // Une note existante s'ouvre en lecture ; une note absente, directement en saisie.
            this.lecture = this.note !== '';
            this.ouverte = true;
        },

        modifier() {
            this.lecture = false;
        },

        fermer() {
            this.ouverte = false;
        },
    };
}
