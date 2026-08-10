/**
 * Liste réordonnable au glisser-déposer.
 *
 * Volontairement bâtie sur les Pointer Events plutôt que sur l'API HTML5 drag & drop :
 * cette dernière est ignorée par les navigateurs tactiles, et l'écran est aussi consulté
 * depuis un téléphone. `touch-action: none` sur la poignée empêche le doigt de faire
 * défiler la page pendant le déplacement.
 *
 * La liste est rendue par Twig, pas par Alpine : on déplace donc les nœuds directement,
 * puis on relit l'ordre obtenu dans le DOM au moment de valider.
 */
export function listeTriable() {
    return {
        ligne: null,

        /** @param {PointerEvent} event */
        saisir(event) {
            // Bouton droit / clic secondaire : on laisse le navigateur faire.
            if (event.button !== 0 && event.pointerType === 'mouse') {
                return;
            }

            this.ligne = event.target.closest('[data-triable]');
            if (this.ligne === null) {
                return;
            }

            event.preventDefault();
            event.target.setPointerCapture(event.pointerId);
            this.ligne.classList.add('is-dragging');
        },

        /** @param {PointerEvent} event */
        deplacer(event) {
            if (this.ligne === null) {
                return;
            }

            const cible = this.voisineSous(event.clientY);
            if (cible === null || cible === this.ligne) {
                return;
            }

            const versLeBas = cible.compareDocumentPosition(this.ligne) & Node.DOCUMENT_POSITION_PRECEDING;
            cible.parentNode.insertBefore(this.ligne, versLeBas ? cible.nextSibling : cible);
        },

        terminer() {
            if (this.ligne === null) {
                return;
            }

            this.ligne.classList.remove('is-dragging');
            this.ligne = null;

            const ordre = [...this.$refs.liste.querySelectorAll('[data-triable]')]
                .map((li) => li.dataset.triable);

            // Rien n'a bougé : inutile d'aller chercher un rechargement et un message.
            if (ordre.join() === this.ordreInitial) {
                return;
            }

            this.$refs.ordre.innerHTML = '';
            for (const id of ordre) {
                const champ = document.createElement('input');
                champ.type = 'hidden';
                champ.name = 'ordre[]';
                champ.value = id;
                this.$refs.ordre.appendChild(champ);
            }

            this.$refs.formulaire.submit();
        },

        /** Ligne dont le pointeur survole la moitié la plus proche. */
        voisineSous(y) {
            for (const li of this.$refs.liste.querySelectorAll('[data-triable]')) {
                const zone = li.getBoundingClientRect();
                if (y >= zone.top && y <= zone.bottom) {
                    return li;
                }
            }

            return null;
        },

        get ordreInitial() {
            return this.$refs.formulaire.dataset.ordreInitial;
        },
    };
}
