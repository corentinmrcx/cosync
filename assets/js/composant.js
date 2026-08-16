/**
 * Assemble un composant Alpine à partir de plusieurs blocs d'état.
 *
 * ⚠️ Ne pas revenir au spread (`{ ...attestationTransport(), ... }`) : l'opérateur
 * **appelle** les getters du bloc et n'en recopie que la valeur. Un
 * `get attestationValide()` évalué sur un bloc encore vide devenait un
 * `attestationValide: false` figé pour la vie de la page — le bouton « Suivant » de
 * l'étape attestation de transport ne se débloquait jamais, dans les trois parcours.
 *
 * Les descripteurs sont donc recopiés tels quels : un getter reste un getter, et
 * Alpine le réévalue à chaque changement des champs qu'il lit.
 *
 * @param {...object} blocs dans l'ordre de priorité croissante (le dernier gagne)
 */
export function assembler(...blocs) {
    const composant = {};

    for (const bloc of blocs) {
        Object.defineProperties(composant, Object.getOwnPropertyDescriptors(bloc));
    }

    return composant;
}
