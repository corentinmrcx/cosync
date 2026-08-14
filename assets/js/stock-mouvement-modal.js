/**
 * Modale d'enregistrement d'un mouvement de stock, ouverte depuis le tableau de gestion.
 *
 * Les données propres à chaque article (jeton CSRF, stock par taille, tailles proposées) sont
 * déposées dans des champs cachés par le serveur : la modale est unique, l'article varie.
 *
 * @param {object} config
 * @param {string} config.urlMouvement URL de soumission, {id} sera remplacé par l'article
 * @param {string} config.urlArticle URL d'édition de l'article, {id} sera remplacé
 * @param {string[]} config.ordreTailles ordre d'affichage des tailles
 */
export function stockMouvementModal(config) {
    return {
        modalOpen: false,
        itemId: null,
        itemNom: '',
        stockActuel: 0,
        taillesStock: {},
        /** Tailles proposées pour cet article : vêtement, pointures, ou aucune. */
        taillesOptions: [],
        typeVetementARenseigner: false,
        action: 'entree',
        quantite: 1,
        taille: '',

        get formAction() {
            return config.urlMouvement.replace('__ID__', String(this.itemId));
        },

        get urlArticle() {
            return config.urlArticle.replace('__ID__', String(this.itemId));
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
            const tailles = brut ? JSON.parse(brut) : {};
            this.taillesStock = tailles.stock ?? {};
            this.taillesOptions = tailles.options ?? [];
            this.typeVetementARenseigner = tailles.typeARenseigner ?? false;

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
