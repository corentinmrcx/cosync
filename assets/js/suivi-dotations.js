/**
 * Suivi des dotations : garder sa place d'un geste à l'autre.
 *
 * Chaque geste d'une ligne (préparer, remettre, corriger une taille) est un formulaire classique :
 * la page se recharge. Seul le groupe du geste se rouvrait, et la page repartait en haut — tout en
 * bas d'une équipe, il fallait redescendre après chaque sac. Au moment d'envoyer, on note les
 * groupes ouverts et le défilement ; au rechargement qui suit, et à celui-là seulement, on les rend.
 *
 * Les groupes sont notés par leur nom, pas par leur rang : une équipe qui apparaît ou disparaît
 * entre deux affichages décalerait tous les rangs.
 */
const CLE = 'dotSuiviPosition';

/** Lu une seule fois, puis effacé : revenir sur l'écran plus tard le rouvre replié. */
function lirePosition() {
    try {
        const brut = sessionStorage.getItem(CLE);
        sessionStorage.removeItem(CLE);

        return brut === null ? null : JSON.parse(brut);
    } catch {
        // Stockage indisponible ou valeur illisible : l'écran s'ouvre replié, rien de cassé.
        return null;
    }
}

/**
 * `articles` : le catalogue du stock, rendu une seule fois pour tout l'écran. Chaque ligne y
 * puise le sélecteur de « Remettre un autre article » — recopier les options dans les
 * centaines de lignes du suivi pesait plus lourd que tout le reste de la page.
 */
export function suiviDotations(articles = []) {
    const position = lirePosition();

    return {
        groupesOuverts: Array.isArray(position?.groupes) ? position.groupes : [],
        articlesRemisables: articles,

        init() {
            if (position === null) {
                return;
            }

            // Les groupes rouverts le sont dès leur premier rendu, sans animation : une fois Alpine
            // passé, la page a sa hauteur finale et le défilement retombe au bon endroit.
            this.$nextTick(() => requestAnimationFrame(() => window.scrollTo(0, Number(position.defilement) || 0)));
        },

        estOuvert(groupe) {
            return this.groupesOuverts.includes(groupe);
        },

        memoriser() {
            const groupes = [...this.$root.querySelectorAll('[data-groupe][aria-expanded="true"]')]
                .map((bouton) => bouton.dataset.groupe);

            try {
                sessionStorage.setItem(CLE, JSON.stringify({ groupes, defilement: window.scrollY }));
            } catch {
                // Rien à mémoriser : la page se rechargera repliée, comme avant.
            }
        },
    };
}
