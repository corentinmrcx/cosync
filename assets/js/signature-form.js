import { assembler } from './composant.js';
import { documentSignatures } from './document-signatures.js';

/**
 * Parcours public réduit à la signature : un document ajouté après l'inscription.
 *
 * Une étape par document, rien d'autre — ni tailles, ni autorisations, ni paiement.
 * Le dossier est déjà complet, redemander le reste ferait resaisir ce qui est acquis.
 */
export function signatureForm({ documents = [] }) {
    return assembler(documentSignatures(documents), {
        step: 0,

        submitting: false,

        get totalSteps() {
            return this.docs.length;
        },

        get displayStep() {
            return this.step + 1;
        },

        get isLastStep() {
            return this.step === this.docs.length - 1;
        },

        get canGoNext() {
            return this.docReady(this.step);
        },

        init() {
            // Le premier document est déjà affiché : son déblocage au scroll ne passera
            // par aucun changement d'étape, il faut donc le déclencher ici.
            this.$nextTick(() => this.markDocScrolledIfShort(0));

            this.$watch('step', (value) => {
                this.$nextTick(() => this.markDocScrolledIfShort(value));
            });

            // Le pad n'existe dans le DOM qu'une fois la case « J'ai lu » cochée.
            this.docs.forEach((_, i) => {
                this.$watch(`docs[${i}].hasRead`, (value) => {
                    if (value === true && this.step === i) {
                        window.requestAnimationFrame(() => this.initDocPad(i));
                    }
                });
            });
        },

        next() {
            if (!this.canGoNext || this.isLastStep) return;
            this.step += 1;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        prev() {
            if (this.step === 0) return;
            this.step -= 1;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
    });
}
