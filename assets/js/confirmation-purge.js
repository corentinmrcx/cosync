/**
 * Verrou de la purge des données de test.
 *
 * Deux garde-fous délibérés : le mot exact à recopier, puis un délai de trois secondes
 * avant que le bouton s'active. L'écran efface toutes les données métier ; le second
 * délai laisse le temps de renoncer après avoir tapé le mot.
 */
export function confirmationPurge(motAttendu = 'SUPPRIMER', delaiSecondes = 3) {
    return {
        mot: '',
        pret: false,
        compteARebours: 0,
        minuteur: null,

        verifier() {
            clearTimeout(this.minuteur);
            this.pret = false;
            this.compteARebours = 0;

            if (this.mot !== motAttendu) return;

            this.compteARebours = delaiSecondes;
            const tic = () => {
                this.compteARebours--;
                if (this.compteARebours <= 0) {
                    this.pret = true;
                } else {
                    this.minuteur = setTimeout(tic, 1000);
                }
            };
            this.minuteur = setTimeout(tic, 1000);
        },
    };
}
