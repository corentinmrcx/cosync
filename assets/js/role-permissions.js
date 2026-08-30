/**
 * Cases à cocher d'un rôle : une écriture entraîne sa lecture.
 *
 * Cocher « enregistrer un paiement » coche et verrouille « consulter les paiements », puis
 * « consulter l'effectif » — les implications se déplient de proche en proche. Sans ça, on
 * compose un rôle qui peut encaisser sur une fiche qu'il n'a pas le droit d'ouvrir, et on ne
 * s'en aperçoit qu'au premier clic de la trésorière.
 *
 * Le verrou est une **aide de saisie**, pas une garantie : c'est `PermissionCollector::completer()`
 * qui fait foi à l'enregistrement. Une case désactivée n'étant pas envoyée par le navigateur,
 * chaque verrou pose un champ caché à sa place.
 *
 * ⚠️ Le déclencheur est `@change`, jamais `@click`. L'input vit dans son `<label>`, qui
 * redispatche le clic vers lui : avec `@click`, `basculer()` était appelé **deux fois** et la
 * case ne changeait pas d'état — elle ne semblait réagir qu'au clic suivant, sur une autre
 * ligne.
 */
export function rolePermissions(initial) {
    return {
        implications: initial.implications,
        cochees: initial.cochees,

        estCochee(valeur) {
            return this.cochees.includes(valeur);
        },

        /** Verrouillée = accordée par une autre case cochée, donc non décochable seule. */
        estVerrouillee(valeur) {
            return this.cochees.some(
                (autre) => autre !== valeur && this.deplier(autre).includes(valeur),
            );
        },

        basculer(valeur) {
            if (this.estVerrouillee(valeur)) {
                return;
            }

            if (this.estCochee(valeur)) {
                this.cochees = this.cochees.filter((v) => v !== valeur);
                return;
            }

            // Les implications entrent aussi dans la sélection, sinon la lecture s'afficherait
            // verrouillée *et* décochée — l'écran dirait le contraire de ce qui sera enregistré.
            // Décocher l'écriture ensuite les laisse cochées mais déverrouillées : c'est le
            // comportement du serveur, qui ne retire jamais un droit qu'on n'a pas retiré.
            this.cochees = [...new Set([...this.cochees, valeur, ...this.deplier(valeur)])];
        },

        /** Tout ce qu'une permission accorde, transitivement. */
        deplier(valeur) {
            const resolues = new Set();
            const aTraiter = [valeur];

            while (aTraiter.length > 0) {
                const courante = aTraiter.pop();

                if (resolues.has(courante)) {
                    continue;
                }

                resolues.add(courante);
                (this.implications[courante] ?? []).forEach((suivante) => aTraiter.push(suivante));
            }

            resolues.delete(valeur);

            return [...resolues];
        },
    };
}
