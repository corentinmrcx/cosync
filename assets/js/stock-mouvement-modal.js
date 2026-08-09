/**
 * Modale d'enregistrement d'un mouvement de stock, ouverte depuis le tableau de gestion.
 *
 * Les données propres à chaque article (jeton CSRF, stock par taille) sont déposées dans
 * des champs cachés par le serveur : la modale est unique, l'article varie.
 *
 * @param {object} config
 * @param {string} config.urlMouvement URL de soumission, {id} sera remplacé par l'article
 * @param {string[]} config.ordreTailles ordre d'affichage des tailles
 */
export function stockMouvementModal(config) {
    return {
        modalOpen: false,
        itemId: null,
        itemNom: '',
        stockActuel: 0,
        taillesStock: {},
        action: 'entree',
        quantite: 1,
        taille: '',

        get formAction() {
            return config.urlMouvement.replace('__ID__', String(this.itemId));
        },

        get csrfToken() {
            return document.getElementById('csrf-' + this.itemId)?.value ?? '';
        },

        /** Les tailles suivent l'ordre du référentiel ; les inconnues ferment la liste. */
        get taillesEntries() {
            const rang = (taille) => {
                const i = config.ordreTailles.indexOf(taille);
                return i === -1 ? config.ordreTailles.length : i;
            };

            return Object.entries(this.taillesStock)
                .sort(([a], [b]) => rang(a) - rang(b) || a.localeCompare(b));
        },

        get dispoTaille() {
            return this.taillesStock[this.taille] ?? 0;
        },

        openModal(id, nom, stock) {
            this.itemId = id;
            this.itemNom = nom;
            this.stockActuel = stock;

            const brut = document.getElementById('tailles-' + id)?.value;
            this.taillesStock = brut ? JSON.parse(brut) : {};

            this.action = 'entree';
            this.quantite = 1;
            this.taille = '';
            this.modalOpen = true;
        },

        closeModal() {
            this.modalOpen = false;
        },
    };
}
