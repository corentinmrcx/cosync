/**
 * Liste déroulante à choix multiples.
 *
 * Le panneau est positionné à la main plutôt qu'en CSS : il est rendu en fin de body
 * pour échapper au dépassement des conteneurs, et bascule au-dessus du champ quand le
 * bas de la fenêtre est trop proche.
 *
 * @param {Array<{value: number|string, label: string}>} options
 * @param {Array<number|string>} selection valeurs déjà retenues
 */
export function multiSelect(options, selection, placeholder = '— Choisir —') {
    const HAUTEUR_PANNEAU = 260;

    return {
        open: false,
        top: 0,
        left: 0,
        width: 0,
        options,
        selected: (selection || []).map(Number),
        placeholder,

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

        isSelected(valeur) {
            return this.selected.includes(Number(valeur));
        },

        pick(valeur) {
            valeur = Number(valeur);
            const i = this.selected.indexOf(valeur);

            if (i === -1) {
                this.selected.push(valeur);
            } else {
                this.selected.splice(i, 1);
            }
        },

        label() {
            if (this.selected.length === 0) return this.placeholder;

            const noms = this.options
                .filter((o) => this.selected.includes(Number(o.value)))
                .map((o) => o.label);

            return noms.length > 2 ? `${noms.length} catégories` : noms.join(', ');
        },
    };
}
