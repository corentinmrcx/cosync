export function completionForm({ manquants = [] }) {
    return {
        manquants,

        // Autorisations (seules celles présentes dans `manquants` sont affichées/requises)
        autorisationPhoto: null,
        autorisationAccident: null,
        autorisationTransportDirigeants: null,
        autorisationTransportParents: null,
        volontaireTransport: null,

        // Attestation transport (uniquement si volontaire === '1')
        nomConducteur: '',
        prenomConducteur: '',
        numPermis: '',
        assuranceNomAdresse: '',
        dateCT: '',
        vehiculeNeuf: false,
        engagementAttestation: false,
        signatureDataAttestation: '',
        signaturePadAttestation: null,

        submitting: false,

        needs(key) {
            return this.manquants.includes(key);
        },

        // L'attestation n'est demandée que si le licencié se déclare volontaire au transport
        get showAttestation() {
            return this.needs('volontaire') && this.volontaireTransport === '1';
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
            // Le pad de signature n'existe qu'une fois le bloc attestation affiché
            this.$watch('volontaireTransport', () => {
                if (this.showAttestation) {
                    this.$nextTick(() => this.initAttestationSignaturePad());
                }
            });
        },

        get canSubmit() {
            if (this.needs('photo') && this.autorisationPhoto === null) return false;
            if (this.needs('accident') && this.autorisationAccident === null) return false;
            if (this.needs('transport_dirigeants') && this.autorisationTransportDirigeants === null) return false;
            if (this.needs('transport_parents') && this.autorisationTransportParents === null) return false;
            if (this.needs('volontaire')) {
                if (this.volontaireTransport === null) return false;
                if (this.volontaireTransport === '1') {
                    return this.nomConducteur !== ''
                        && this.prenomConducteur !== ''
                        && this.numPermis !== ''
                        && this.assuranceNomAdresse !== ''
                        && (this.vehiculeNeuf || (this.dateCT !== '' && !this.dateCTFuture))
                        && this.engagementAttestation
                        && this.signatureDataAttestation !== '';
                }
            }
            return true;
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
