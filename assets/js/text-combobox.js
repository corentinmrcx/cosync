export function textCombobox(suggestions, current) {
    return {
        suggestions: [...suggestions],
        query: current ?? '',
        open: false,

        get filtered() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.suggestions;
            return this.suggestions.filter(s => s.toLowerCase().includes(q));
        },

        get showCreate() {
            const q = this.query.trim();
            if (!q) return false;
            return !this.suggestions.some(s => s.toLowerCase() === q.toLowerCase());
        },

        select(value) {
            this.query = value;
            this.open = false;
        },

        clear() {
            this.query = '';
            this.open = false;
        },

        removeSuggestion(value) {
            this.suggestions = this.suggestions.filter(s => s !== value);
            if (this.query === value) this.query = '';
        },
    };
}
