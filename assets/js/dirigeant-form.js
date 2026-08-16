import { assembler } from './composant.js';
import { documentSignatures } from './document-signatures.js';
import { attestationTransport } from './attestation-transport.js';

/**
 * Formulaire public du dossier dirigeant.
 *
 * Les étapes 1 à 4 sont fixes ; les documents à signer occupent ensuite une étape
 * chacun, à partir de 5. Leur nombre dépend de la saison et du ciblage du dirigeant,
 * il n'est donc pas connu du code : `documents` est fourni par le serveur.
 */
export function dirigeantForm({ needTaille, needPhoto, needTransport, documents }) {
    return assembler(attestationTransport(), documentSignatures(documents), {
        needTaille,
        needPhoto,
        needTransport,

        step: 1,

        // Étape 2 — équipement
        tailleHaut: '',
        tailleBas: '',
        pointure: '',

        // Étape 3 — autorisations
        autorisationPhoto: null,
        volontaireTransport: null,

        // Étape 4 — attestation transport (uniquement si volontaireTransport === '1')

        submitting: false,

        /** Numéro d'étape du document de rang `index`. */
        documentStep(index) {
            return 5 + index;
        },

        /** Rang du document affiché à l'étape courante, ou -1 hors des étapes documents. */
        get currentDocumentIndex() {
            return this.step >= 5 ? this.step - 5 : -1;
        },

        // Étapes réellement accessibles, calculées selon les champs à collecter
        get steps() {
            const s = [1];
            if (this.needTaille) s.push(2);
            if (this.needPhoto || this.needTransport) s.push(3);
            if (this.needTransport && this.volontaireTransport === '1') s.push(4);
            this.docs.forEach((_, i) => s.push(this.documentStep(i)));
            return s;
        },

        get totalSteps() {
            return this.steps.length;
        },

        get displayStep() {
            const idx = this.steps.indexOf(this.step);
            return idx === -1 ? 1 : idx + 1;
        },

        get isLastStep() {
            return this.step === this.steps[this.steps.length - 1];
        },

        init() {
            // Si transport repasse à « non » et qu'on est sur l'étape attestation → reculer
            this.$watch('volontaireTransport', (val) => {
                if (val !== '1' && this.step === 4) {
                    this.step = 3;
                }
            });

            this.$watch('step', (value) => {
                if (value === 4) {
                    this.$nextTick(() => this.initAttestationSignaturePad());
                }
                if (value >= 5) {
                    this.$nextTick(() => this.markDocScrolledIfShort(value - 5));
                }
            });

            // Le pad n'existe dans le DOM qu'une fois la case « J'ai lu » cochée.
            this.docs.forEach((_, i) => {
                this.$watch(`docs[${i}].hasRead`, (value) => {
                    if (value === true && this.step === this.documentStep(i)) {
                        window.requestAnimationFrame(() => this.initDocPad(i));
                    }
                });
            });
        },

        get canGoNext() {
            switch (this.step) {
                case 1:
                    return true;
                case 2:
                    return this.tailleHaut !== '' && this.tailleBas !== '' && this.pointure !== '';
                case 3:
                    if (this.needPhoto && this.autorisationPhoto === null) return false;
                    if (this.needTransport && this.volontaireTransport === null) return false;
                    return true;
                case 4: // attestation transport
                    return this.attestationValide;
                default: // étapes documents
                    return this.docReady(this.currentDocumentIndex);
            }
        },

        next() {
            if (!this.canGoNext) return;
            const idx = this.steps.indexOf(this.step);
            if (idx < this.steps.length - 1) {
                this.step = this.steps[idx + 1];
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        prev() {
            const idx = this.steps.indexOf(this.step);
            if (idx > 0) {
                this.step = this.steps[idx - 1];
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

    });
}
