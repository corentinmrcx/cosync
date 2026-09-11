/**
 * Menu « ⋯ » d'une ligne de tableau.
 *
 * Le panneau du menu de fiche est en position **absolue** : posé dans un `.table-wrapper`,
 * qui porte `overflow-x: auto`, il se ferait rogner par le cadre et ferait apparaître une
 * barre de défilement au lieu de s'ouvrir. Celui-ci est rendu en position **fixe**, ses
 * coordonnées calculées à l'ouverture — comme la liste déroulante de `select-liste` — donc
 * plus rien ne le découpe.
 *
 * Sur téléphone, le panneau redevient la feuille ancrée en bas d'écran des menus de fiche :
 * c'est le CSS seul qui la place, on ne pose alors aucune coordonnée.
 */
export function menuLigne() {
    const MARGE = 6;

    return {
        ouvert: false,
        ancrage: {},

        get enFeuille() {
            return window.matchMedia('(max-width: 640px)').matches;
        },

        basculer() {
            this.ouvert = !this.ouvert;
            this.ancrage = {};

            if (this.ouvert && !this.enFeuille) {
                // Après le rendu : un panneau encore caché n'a pas de dimensions à mesurer.
                this.$nextTick(() => this.ancrer());
            }
        },

        fermerMenu() {
            this.ouvert = false;
        },

        /** Aligné à droite du déclencheur, et basculé au-dessus quand le bas manque. */
        ancrer() {
            const bouton = this.$refs.declencheur.getBoundingClientRect();
            const panneau = this.$refs.panneau.getBoundingClientRect();
            const manqueDePlace = window.innerHeight - bouton.bottom < panneau.height + MARGE;

            this.ancrage = {
                top: `${manqueDePlace ? bouton.top - panneau.height - MARGE : bouton.bottom + MARGE}px`,
                left: `${Math.max(MARGE, bouton.right - panneau.width)}px`,
            };
        },
    };
}
