/**
 * Liste déroulante stylée, à la place d'un <select> natif.
 *
 * Le panneau est positionné à la main : rendu hors du flux, il échappe au dépassement
 * des conteneurs, et bascule au-dessus du champ quand le bas de la fenêtre est trop
 * proche — sur mobile, un panneau qui sort de l'écran est inutilisable.
 */
export function selectListe() {
    const HAUTEUR_PANNEAU = 230;

    return {
        open: false,
        top: 0,
        left: 0,
        width: 0,

        toggle() {
            if (!this.open) {
                const zone = this.$refs.trigger.getBoundingClientRect();
                const manqueDePlace = window.innerHeight - zone.bottom < HAUTEUR_PANNEAU;

                this.top = manqueDePlace ? zone.top - HAUTEUR_PANNEAU - 4 : zone.bottom + 4;
                this.left = zone.left;
                this.width = zone.width;
            }
            this.open = !this.open;
        },
    };
}
