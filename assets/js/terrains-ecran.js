/**
 * Référentiel des terrains : deux modales, l'ajout et le renommage.
 *
 * Les deux gestes sont rares — on ne décrit un complexe qu'une fois — et n'ont donc rien à
 * faire en formulaire déplié dans la page ni en champ de saisie posé dans chaque ligne du
 * tableau. Le nom se lit ; il se modifie depuis le menu « ⋯ » de sa ligne.
 *
 * @param {object} config
 * @param {string} config.urlNouveau URL de création, vide si le compte n'a pas le droit
 * @param {string} config.urlRenommer URL de renommage, `__ID__` à remplacer
 */
export function terrainsEcran(config) {
    return {
        ajoutOuvert: false,
        renommageOuvert: false,
        terrainId: null,
        nom: '',
        token: '',

        get urlNouveau() {
            return config.urlNouveau;
        },

        get urlRenommer() {
            return config.urlRenommer.replace('__ID__', String(this.terrainId));
        },

        ouvrirAjout() {
            this.nom = '';
            this.ajoutOuvert = true;
            this.$nextTick(() => this.$refs.champAjout?.focus());
        },

        /** Le terrain est décrit par les attributs `data-` de son entrée de menu. */
        ouvrirRenommage(element) {
            const donnees = element.dataset;

            this.terrainId = donnees.id;
            this.nom = donnees.nom;
            this.token = donnees.token;
            this.renommageOuvert = true;
            this.$nextTick(() => this.$refs.champRenommage?.select());
        },

        fermer() {
            this.ajoutOuvert = false;
            this.renommageOuvert = false;
        },
    };
}
