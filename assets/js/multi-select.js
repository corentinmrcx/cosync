/**
 * Liste déroulante à choix multiples.
 *
 * Le panneau est positionné à la main plutôt qu'en CSS : il est rendu en position fixe
 * pour échapper au dépassement des conteneurs, et bascule au-dessus du champ quand le
 * bas de la fenêtre est trop proche.
 *
 * Les valeurs sont comparées comme des chaînes : un id d'équipe, un UUID de licencié et
 * un slug de rôle empruntent le même chemin.
 *
 * @param {Object}   config
 * @param {Array<{value: number|string, label: string}>} config.options
 * @param {Array<number|string>} config.selection    valeurs déjà retenues
 * @param {Array<number|string>} config.verrouillees valeurs cochées que l'on ne peut pas défaire
 * @param {string}   config.placeholder
 * @param {string}   config.unite  nom du compte affiché au-delà de deux valeurs retenues
 */
export function multiSelect(config = {}) {
    const HAUTEUR_PANNEAU = 260;
    const chaines = (valeurs) => (valeurs || []).map(String);

    return {
        open: false,
        top: 0,
        left: 0,
        width: 0,
        recherche: '',
        options: config.options || [],
        selected: chaines(config.selection),
        verrouillees: chaines(config.verrouillees),
        placeholder: config.placeholder || '— Choisir —',
        unite: config.unite || 'éléments',

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

        estVerrouillee(valeur) {
            return this.verrouillees.includes(String(valeur));
        },

        isSelected(valeur) {
            return this.selected.includes(String(valeur)) || this.estVerrouillee(valeur);
        },

        estVide() {
            return this.selected.length === 0;
        },

        pick(valeur) {
            valeur = String(valeur);

            if (this.estVerrouillee(valeur)) return;

            const i = this.selected.indexOf(valeur);

            if (i === -1) {
                this.selected.push(valeur);
            } else {
                this.selected.splice(i, 1);
            }

            this.prevenir();
        },

        correspond(label) {
            const q = this.recherche.trim().toLowerCase();

            return q === '' || label.includes(q);
        },

        /** Ne touche que ce que la recherche laisse voir, et jamais une valeur verrouillée. */
        tout(valeur) {
            const visibles = this.options
                .filter((o) => this.correspond(o.label.toLowerCase()) && !this.estVerrouillee(o.value))
                .map((o) => String(o.value));

            this.selected = valeur
                ? [...new Set([...this.selected, ...visibles])]
                : this.selected.filter((v) => !visibles.includes(v));

            this.prevenir();
        },

        /**
         * Ce que le champ s'apprête à retenir — les valeurs verrouillées en sont exclues :
         * elles sont déjà enregistrées, les compter ici ferait mentir le compte de l'action.
         */
        label() {
            const retenues = this.options
                .filter((o) => this.selected.includes(String(o.value)))
                .map((o) => o.label);

            if (retenues.length === 0) return this.placeholder;

            return retenues.length > 2 ? `${retenues.length} ${this.unite}` : retenues.join(', ');
        },

        prevenir() {
            this.$dispatch('multi-select', { nombre: this.selected.length });
        },
    };
}
