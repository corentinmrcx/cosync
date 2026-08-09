/**
 * Pré-remplissage du formulaire dirigeant depuis un licencié.
 *
 * Un dirigeant est souvent aussi licencié : choisir son nom dans la liste recopie ce
 * qu'il a déjà déclaré, plutôt que de le lui redemander. Les champs déjà saisis à la
 * main ne sont écrasés que si le licencié porte une valeur.
 *
 * @param {object} config
 * @param {object} config.licencies      données indexées par UUID de licencié
 * @param {object} config.champs         id des champs du formulaire, par nom logique
 * @param {string} config.licencieDejaLie nom affiché si un licencié est déjà rattaché
 */
export function dirigeantPrefill(config) {
    const champ = (nom) => document.getElementById(config.champs[nom]);

    const selecteur = champ('licencie');
    const bandeau = document.getElementById('dirigeant-sync-banner');
    const nomAffiche = document.getElementById('dirigeant-sync-name');

    if (!selecteur) return;

    const afficherBandeau = (nom) => {
        if (bandeau) bandeau.style.display = nom ? 'flex' : 'none';
        if (nomAffiche && nom) nomAffiche.textContent = nom;
    };

    const recopier = (uuid) => {
        const licencie = config.licencies[uuid];

        if (!licencie) {
            afficherBandeau(null);
            return;
        }

        for (const [nom, valeur] of Object.entries(licencie)) {
            const element = champ(nom);
            if (element && valeur) element.value = valeur;
        }

        afficherBandeau(selecteur.options[selecteur.selectedIndex].text);
    };

    selecteur.addEventListener('change', () => recopier(selecteur.value));

    if (config.licencieDejaLie) {
        afficherBandeau(config.licencieDejaLie);
    }
}
