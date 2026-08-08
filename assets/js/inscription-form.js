import { documentSignatures } from './document-signatures.js';

export function inscriptionForm({
    isJeune,
    montant,
    documents = [],
    demo = false,
    demoUrl = '',
    dotationGroupes = [],
    dotationFlocages = {},
    dotationAutoFlocages = [],
}) {
    return {
        ...documentSignatures(documents),

        step: 1,

        /**
         * Le paiement reste la dernière étape, mais le nombre de documents à signer
         * avant lui dépend de la saison. Un numéro haut le met hors de portée du bloc
         * documents, qui occupe 5, 6, 7… selon les cas.
         */
        paymentStep: 100,
        isJeune,
        montant,

        // Mode démonstration — n'enregistre rien, faux loader avant la confirmation
        demo,
        demoUrl,

        // Étape 2
        tailleHaut: '',
        tailleBas: '',
        pointure: '',

        // Étape 2 — choix de dotation (groupes « 1 parmi N » configurés par l'admin)
        dotationGroupes,
        dotationChoix: {},

        // Étape 2 — textes de personnalisation (flocage) + confirmation d'orthographe
        dotationFlocages,
        dotationAutoFlocages,
        dotationPersonnalisation: {},
        flocageConfirme: false,

        // Étape 3 — autorisations
        autorisationPhoto: null,
        autorisationTransportDirigeants: null,
        autorisationTransportParents: null,
        autorisationAccident: null,
        volontaireTransport: null,

        // Étape 4 — attestation transport (uniquement si volontaireTransport === '1')
        nomConducteur: '',
        prenomConducteur: '',
        numPermis: '',
        assuranceNomAdresse: '',
        dateCT: '',
        vehiculeNeuf: false,
        engagementAttestation: false,
        signatureDataAttestation: '',
        signaturePadAttestation: null,

        // Étapes 5+ — documents à lire et signer : état fourni par documentSignatures()

        // Dernière étape — paiement
        paymentMode: '',
        multiPayment: false,
        paymentModes: [],

        submitting: false,

        /** Numéro d'étape du document de rang `index`. */
        documentStep(index) {
            return 5 + index;
        },

        /** Rang du document affiché à l'étape courante, ou -1 hors des étapes documents. */
        get currentDocumentIndex() {
            return this.step >= 5 && this.step !== this.paymentStep ? this.step - 5 : -1;
        },

        // Séquence des étapes réellement accessibles : socle, attestation éventuelle,
        // un document par étape, puis le paiement.
        get steps() {
            const s = [1, 2, 3];
            if (this.isJeune && this.volontaireTransport === '1') s.push(4);
            this.docs.forEach((_, i) => s.push(this.documentStep(i)));
            s.push(this.paymentStep);
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

        // Textes de flocage effectivement demandés au vu des choix courants.
        get flocagesActifs() {
            const out = [];
            for (const [groupe, options] of Object.entries(this.dotationFlocages)) {
                const choisi = this.dotationChoix[groupe];
                // Le `value` d'un radio est toujours une chaîne, l'id vient de PHP en nombre.
                const opt = options.find(o => String(o.id) === String(choisi));
                if (opt !== undefined) {
                    out.push({ cle: groupe, max: opt.max, article: opt.article, texte: (this.dotationPersonnalisation[groupe] || '').trim() });
                }
            }
            for (const auto of this.dotationAutoFlocages) {
                out.push({ cle: auto.cle, max: auto.max, article: auto.article, texte: (this.dotationPersonnalisation[auto.cle] || '').trim() });
            }
            return out;
        },

        // Miroir de FLOCAGE_PATTERN côté serveur : confort de saisie, la validation qui fait foi
        // reste celle de DotationChoixRequestFactory.
        get flocagesValides() {
            const autorise = /^[\p{L}\p{N} .'\-]+$/u;
            return this.flocagesActifs.every(f => f.texte !== '' && f.texte.length <= f.max && autorise.test(f.texte));
        },

        // Vrai si l'utilisateur a saisi une date de CT strictement dans le futur (aujourd'hui autorisé)
        get dateCTFuture() {
            if (this.dateCT === '') return false;
            const d = new Date();
            const todayStr = d.getFullYear() + '-'
                + String(d.getMonth() + 1).padStart(2, '0') + '-'
                + String(d.getDate()).padStart(2, '0');
            return this.dateCT > todayStr;
        },

        init() {
            // Si volontaireTransport passe à non et qu'on est sur l'étape attestation → reculer
            this.$watch('volontaireTransport', (val) => {
                if (val !== '1' && this.step === 4) {
                    this.step = 3;
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

            this.$watch('step', (value) => {
                if (value === 4) {
                    this.$nextTick(() => this.initAttestationSignaturePad());
                }
                if (value >= 5 && value !== this.paymentStep) {
                    this.$nextTick(() => this.markDocScrolledIfShort(value - 5));
                }
            });
        },

        get canGoNext() {
            switch (this.step) {
                case 1:
                    return true;
                case 2:
                    if (this.tailleHaut === '' || this.tailleBas === '' || this.pointure === '') return false;
                    if (!this.dotationGroupes.every(g => this.dotationChoix[g] !== undefined && this.dotationChoix[g] !== '')) return false;
                    if (!this.flocagesValides) return false;
                    return this.flocagesActifs.length === 0 || this.flocageConfirme;
                case 3:
                    if (this.autorisationPhoto === null) return false;
                    if (this.isJeune) {
                        return this.autorisationAccident !== null
                            && this.autorisationTransportDirigeants !== null
                            && this.autorisationTransportParents !== null
                            && this.volontaireTransport !== null;
                    }
                    return true;
                case 4: // attestation transport
                    return this.nomConducteur !== ''
                        && this.prenomConducteur !== ''
                        && this.numPermis !== ''
                        && this.assuranceNomAdresse !== ''
                        && (this.vehiculeNeuf || (this.dateCT !== '' && !this.dateCTFuture))
                        && this.engagementAttestation
                        && this.signatureDataAttestation !== '';
                case this.paymentStep:
                    return this.multiPayment ? this.paymentModes.length > 0 : this.paymentMode !== '';
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

        initAttestationSignaturePad() {
            const canvas = this.$refs.signatureCanvasAttestation;
            if (!canvas || this.signaturePadAttestation) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width  = canvas.offsetWidth  * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            this.signaturePadAttestation = new SignaturePad(canvas, {
                backgroundColor: 'rgb(255, 255, 255)',
                penColor: 'rgb(0, 0, 0)',
            });

            this.signaturePadAttestation.addEventListener('endStroke', () => {
                this.signatureDataAttestation = this.signaturePadAttestation.toDataURL('image/png');
            });
        },

        clearAttestationSignature() {
            if (this.signaturePadAttestation) {
                this.signaturePadAttestation.clear();
                this.signatureDataAttestation = '';
            }
        },

        togglePaymentMode(value) {
            const idx = this.paymentModes.indexOf(value);
            if (idx === -1) this.paymentModes.push(value);
            else            this.paymentModes.splice(idx, 1);
        },

        isPaymentModeActive(value) {
            return this.multiPayment
                ? this.paymentModes.includes(value)
                : this.paymentMode === value;
        },

        /**
         * Paiement par carte : enregistre l'inscription puis part sur HelloAsso.
         * Bouton de type "button" et soumission explicite, pour qu'un appui sur Entrée
         * dans un champ des étapes précédentes ne déclenche jamais un paiement.
         */
        payByCard(el) {
            if (this.submitting) return;
            if (this.demo) {
                this.demoSubmit();
                return;
            }
            this.submitting = true;
            this.$refs.payOnlineFlag.value = '1';
            el.closest('form').requestSubmit();
        },

        // Soumission en mode démo : faux loader puis redirection, aucun enregistrement
        demoSubmit() {
            if (this.submitting) return;
            this.submitting = true;
            window.setTimeout(() => {
                window.location.href = this.demoUrl;
            }, 3500);
        },
    };
}
