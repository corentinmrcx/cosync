export function dirigeantForm({ needTaille, needPhoto, needTransport }) {
    return {
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
        nomConducteur: '',
        prenomConducteur: '',
        numPermis: '',
        assuranceNomAdresse: '',
        dateCT: '',
        engagementAttestation: false,
        signatureDataAttestation: '',
        signaturePadAttestation: null,

        submitting: false,

        // Étapes réellement accessibles, calculées selon les champs à collecter
        get steps() {
            const s = [1];
            if (this.needTaille) s.push(2);
            if (this.needPhoto || this.needTransport) s.push(3);
            if (this.needTransport && this.volontaireTransport === '1') s.push(4);
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
                    return this.nomConducteur !== ''
                        && this.prenomConducteur !== ''
                        && this.numPermis !== ''
                        && this.assuranceNomAdresse !== ''
                        && this.dateCT !== ''
                        && !this.dateCTFuture
                        && this.engagementAttestation
                        && this.signatureDataAttestation !== '';
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
    };
}
