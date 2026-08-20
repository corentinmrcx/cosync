/**
 * Formulaire de nouvelle correspondance d'écoulement.
 *
 * Le second sélecteur ne liste que les articles réellement liables au principal choisi :
 * même type de vêtement, et pas l'article lui-même. Les masquer parmi les autres — la
 * première version le faisait — laissait une liste pleine de trous où l'on cherchait en
 * vain l'article attendu. Le serveur refait le contrôle : un onglet resté ouvert pendant
 * qu'un type de vêtement changeait enverrait sinon un couple incohérent.
 */
export function ecoulementForm(initial) {
    return {
        ouverture: false,
        principal: '',
        aEcouler: '',
        principaux: initial.principaux,
        candidats: initial.candidats,

        init() {
            // Changer de principal change la liste d'en face : un article retenu pour le
            // précédent n'y est peut-être plus, et il partirait au serveur sans être visible.
            this.$watch('principal', () => {
                this.aEcouler = '';
            });
        },

        get candidatsFiltres() {
            const choisi = this.principaux.find((p) => String(p.id) === String(this.principal));

            if (choisi === undefined) {
                return [];
            }

            return this.candidats.filter(
                (candidat) => candidat.id !== choisi.id && candidat.type === choisi.type,
            );
        },
    };
}
