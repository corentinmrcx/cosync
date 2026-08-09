/**
 * Sommaire de la documentation : met en surbrillance la section à l'écran.
 *
 * La marge d'observation cible le tiers haut du viewport : sans elle, deux sections
 * seraient actives en même temps pendant le défilement.
 */
export function sommaireActif(sectionParDefaut = 'vue-ensemble') {
    return {
        active: sectionParDefaut,

        init() {
            const observateur = new IntersectionObserver(
                (entrees) => entrees
                    .filter((entree) => entree.isIntersecting)
                    .forEach((entree) => { this.active = entree.target.id; }),
                { rootMargin: '-25% 0px -65% 0px', threshold: 0 },
            );

            this.$el.querySelectorAll('.doc-section').forEach((section) => observateur.observe(section));
        },
    };
}
