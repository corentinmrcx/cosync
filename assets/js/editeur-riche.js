/**
 * Éditeur de texte enrichi des écrans de configuration (règlement, attestation).
 *
 * Quill est chargé à la demande : deux écrans sur cinquante l'utilisent, il n'a pas à
 * peser sur le reste de l'application.
 *
 * Le contenu produit est recopié dans un champ caché à la soumission : Quill travaille
 * sur un div, pas sur un <textarea>, et le formulaire ne verrait rien sans cela.
 *
 * @param {object} config
 * @param {string} config.editeur     sélecteur du conteneur de l'éditeur
 * @param {string} config.formulaire  id du formulaire qui porte le champ caché
 * @param {string} config.champCache  id du champ recevant le HTML produit
 * @param {string} config.apercu      id du bloc d'aperçu tenu à jour en direct
 * @param {string} config.placeholder texte affiché tant que l'éditeur est vide
 */
export async function initEditeurRiche(config) {
    const [{ default: Quill }] = await Promise.all([
        import('quill'),
        import('quill/dist/quill.snow.css'),
    ]);

    const editeur = new Quill(config.editeur, {
        theme: 'snow',
        placeholder: config.placeholder,
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ['bold', 'italic', 'underline'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['clean'],
            ],
        },
    });

    document.getElementById(config.formulaire).addEventListener('submit', () => {
        document.getElementById(config.champCache).value = editeur.root.innerHTML;
    });

    const apercu = document.getElementById(config.apercu);
    const rafraichir = () => { apercu.innerHTML = editeur.root.innerHTML; };

    rafraichir();
    editeur.on('text-change', rafraichir);
}
