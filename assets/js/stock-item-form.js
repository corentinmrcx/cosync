/**
 * Formulaire d'article de stock : les champs qui n'ont de sens que pour certains articles.
 *
 * Une contenance ne concerne que l'épicerie, une couleur et un type de vêtement que
 * l'équipement, et une grille de tailles que l'équipement dont on sait à quelle échelle il se
 * mesure. `echelle` porte cette dernière règle : un haut et un bas se disent en tailles de
 * vêtement, les pieds en pointures — c'est ce qui décide des grilles proposées.
 */
export function stockItemForm(initial) {
    return {
        kind: initial.kind,
        typeVetement: initial.typeVetement,
        grille: initial.grille,
        remplace: initial.remplace,

        init() {
            // Changer de type de vêtement change d'échelle : la grille retenue jusque-là ne
            // traduit plus la bonne chose, on repart d'aucune plutôt que d'en garder une fausse.
            // L'article écoulé tombe pour la même raison : il n'est plus du même type, la
            // dotation irait lire la taille du bas pour servir un haut.
            this.$watch('typeVetement', () => {
                this.grille = '';
                this.remplace = '';
            });
        },

        get echelle() {
            if (this.typeVetement === '') {
                return '';
            }

            return this.typeVetement === 'chaussures' ? 'pointure' : 'vetement';
        },
    };
}
