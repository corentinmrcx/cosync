/**
 * État et comportements de la (des) étape(s) « Lecture + signature d'un document ».
 * Utilisé par le formulaire licencié comme par le formulaire dirigeant.
 * S'intègre dans un composant Alpine via le spread (`...documentSignatures(docs)`).
 *
 * Le nombre de documents n'étant plus fixé par le code, l'état est indexé : chaque
 * document a son propre déblocage au scroll, sa case « J'ai lu » et son pad.
 *
 * Références x-ref attendues dans le template, suffixées par l'index :
 * documentEl0, signatureCanvas0, documentEl1, signatureCanvas1…
 *
 * @param {Array<{id: number}>} documents documents à signer, dans l'ordre des étapes
 */
export function documentSignatures(documents) {
    return {
        docs: documents.map((doc) => ({
            id: doc.id,
            scrolled: false,
            hasRead: false,
            signatureData: '',
            pad: null,
        })),

        onDocScroll(index, event) {
            const doc = this.docs[index];
            if (!doc || doc.scrolled) return;

            const el = event.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
                doc.scrolled = true;
            }
        },

        // À appeler au moment où l'étape devient visible : si le texte tient sans
        // scroll, on débloque directement la case « J'ai lu ».
        markDocScrolledIfShort(index) {
            const doc = this.docs[index];
            const el = this.$refs['documentEl' + index];

            if (doc && el && el.scrollHeight <= el.clientHeight) {
                doc.scrolled = true;
            }
        },

        initDocPad(index) {
            const doc = this.docs[index];
            const canvas = this.$refs['signatureCanvas' + index];
            if (!doc || !canvas || doc.pad) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width  = canvas.offsetWidth  * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            doc.pad = new SignaturePad(canvas, {
                backgroundColor: 'rgb(255, 255, 255)',
                penColor: 'rgb(0, 0, 0)',
            });

            doc.pad.addEventListener('endStroke', () => {
                doc.signatureData = doc.pad.toDataURL('image/png');
            });
        },

        clearDocSignature(index) {
            const doc = this.docs[index];
            if (doc && doc.pad) {
                doc.pad.clear();
                doc.signatureData = '';
            }
        },

        /** Le document est-il lu, accepté et signé ? Condition de passage à l'étape suivante. */
        docReady(index) {
            const doc = this.docs[index];
            return !!doc && doc.hasRead && doc.signatureData !== '';
        },
    };
}
