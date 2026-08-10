/**
 * Combobox texte : suggestions filtrées + saisie libre optionnelle.
 *
 * `suggestions` accepte deux formes :
 *   - des chaînes            → la valeur soumise est le texte lui-même (marque, couleur…)
 *   - des { value, label }   → la valeur soumise est `value`, l'utilisateur voit `label`
 *
 * `allowCreate` (défaut true) autorise une valeur hors liste. À false, le champ se comporte
 * comme un select cherchable : pas de « + Créer », pas de suppression de suggestion, et toute
 * saisie qui ne correspond à aucune option est annulée à la fermeture.
 */
export function textCombobox(suggestions, current, allowCreate = true) {
    const options = suggestions.map(s => (typeof s === 'string' ? { value: s, label: s } : s));
    const selected = options.find(o => o.value === current) ?? null;
    const freeText = allowCreate && selected === null ? (current ?? '') : '';

    return {
        options,
        allowCreate,
        query: selected ? selected.label : freeText,
        value: selected ? selected.value : freeText,
        open: false,

        init() {
            this.$watch('query', () => this.syncValue());
        },

        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.options;
            return this.options.filter(o => o.label.toLowerCase().includes(q));
        },

        get showCreate() {
            if (!this.allowCreate) return false;
            const q = this.query.trim();
            if (!q) return false;
            return !this.options.some(o => o.label.toLowerCase() === q.toLowerCase());
        },

        /** Aucune option ne correspond et on ne peut rien créer : il faut le dire. */
        get showEmpty() {
            return !this.allowCreate && this.filtered.length === 0;
        },

        /** Répercute la saisie clavier sur la valeur soumise. */
        syncValue() {
            if (this.allowCreate) {
                this.value = this.query;
                return;
            }
            const q = this.query.trim().toLowerCase();
            const match = this.options.find(o => o.label.toLowerCase() === q);
            this.value = match ? match.value : '';
        },

        select(option) {
            this.query = option.label;
            this.value = option.value;
            this.open = false;
        },

        /** Fermeture du dropdown : en liste fermée, on ne laisse jamais un texte sans valeur. */
        close() {
            this.open = false;
            if (this.allowCreate) return;
            const current = this.options.find(o => o.value === this.value);
            this.query = current ? current.label : '';
        },

        clear() {
            this.query = '';
            this.value = '';
            this.open = false;
        },

        removeSuggestion(option) {
            this.options = this.options.filter(o => o.value !== option.value);
            if (this.value === option.value) this.clear();
        },
    };
}
