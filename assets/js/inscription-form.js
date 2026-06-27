export function inscriptionForm({ isJeune, montant }) {
    return {
        step: 1,
        isJeune,
        montant,

        // Étape 2
        tailleHaut: '',
        tailleBas: '',
        pointure: '',

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
        engagementAttestation: false,
        signatureDataAttestation: '',
        signaturePadAttestation: null,

        // Étape 5 — règlement + signature
        reglementScrolled: false,
        hasRead: false,
        signatureData: '',
        signaturePad: null,

        // Étape 6 — paiement
        paymentMode: '',
        multiPayment: false,
        paymentModes: [],

        submitting: false,

        // Séquence des étapes réellement accessibles (5 ou 6 selon volontaireTransport)
        get steps() {
            const s = [1, 2, 3];
            if (this.isJeune && this.volontaireTransport === '1') s.push(4);
            s.push(5, 6);
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

            this.$watch('hasRead', (value) => {
                if (value === true && this.step === 5) {
                    window.requestAnimationFrame(() => this.initSignaturePad());
                }
            });

            this.$watch('step', (value) => {
                if (value === 4) {
                    this.$nextTick(() => this.initAttestationSignaturePad());
                }
                if (value === 5) {
                    this.$nextTick(() => {
                        const el = this.$refs.reglementEl;
                        if (!el) return;
                        if (el.scrollHeight <= el.clientHeight) {
                            this.reglementScrolled = true;
                        }
                    });
                }
            });
        },

        onReglementScroll(event) {
            if (this.reglementScrolled) return;
            const el = event.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
                this.reglementScrolled = true;
            }
        },

        get canGoNext() {
            switch (this.step) {
                case 1:
                    return true;
                case 2:
                    return this.tailleHaut !== '' && this.tailleBas !== '' && this.pointure !== '';
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
                        && this.dateCT !== ''
                        && !this.dateCTFuture
                        && this.engagementAttestation
                        && this.signatureDataAttestation !== '';
                case 5: // règlement
                    return this.hasRead && this.signatureData !== '';
                case 6: // paiement
                    return this.multiPayment ? this.paymentModes.length > 0 : this.paymentMode !== '';
                default:
                    return false;
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

        initSignaturePad() {
            const canvas = this.$refs.signatureCanvas;
            if (!canvas || this.signaturePad) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width  = canvas.offsetWidth  * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            this.signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgb(255, 255, 255)',
                penColor: 'rgb(0, 0, 0)',
            });

            this.signaturePad.addEventListener('endStroke', () => {
                this.signatureData = this.signaturePad.toDataURL('image/png');
            });
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

        clearSignature() {
            if (this.signaturePad) {
                this.signaturePad.clear();
                this.signatureData = '';
            }
        },
    };
}
