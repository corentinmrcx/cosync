/**
 * Modale de correction d'un mouvement de stock, ouverte depuis l'historique.
 *
 * La modale est unique : chaque ligne lui passe, au clic, tout ce qu'elle doit savoir —
 * son URL d'envoi, la quantité et la taille actuelles, les tailles proposées pour
 * l'article, et son jeton CSRF.
 */
export function stockCorrectionModal() {
    return {
        ouverte: false,
        action: '',
        article: '',
        taillesOptions: [],
        token: '',
        quantite: 1,
        taille: '',
        motif: '',

        /** Rien à enregistrer tant que le motif manque ou que rien n'a bougé. */
        get pretAEnregistrer() {
            return this.motif.trim() !== '' && this.quantite >= 1;
        },

        ouvrir(donnees) {
            this.action = donnees.action;
            this.article = donnees.article;
            this.taillesOptions = donnees.options ?? [];
            this.token = donnees.token;
            this.quantite = donnees.quantite;
            this.taille = donnees.taille ?? '';
            this.motif = '';
            this.ouverte = true;
        },

        fermer() {
            this.ouverte = false;
        },
    };
}
