export function inscriptionForm({ isEcoleFoot, montant }) {
    return {
        step: 1,
        isEcoleFoot,
        montant,

        // Étape 2
        tailleHaut: '',
        tailleBas: '',
        pointure: '',

        // Étape 3
        autorisationPhoto: null,
        autorisationTransportDirigeants: null,
        autorisationTransportParents: null,

        // Étape 4
        hasRead: false,
        signatureData: '',
        signaturePad: null,

        // Étape 5
        paymentMode: '',

        init() {
            // Le canvas est dans un x-show="hasRead" — l'initialiser quand hasRead devient vrai
            this.$watch('hasRead', (value) => {
                if (value === true && this.step === 4) {
                    window.requestAnimationFrame(() => this.initSignaturePad());
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
                    if (this.autorisationPhoto === null) return false;
                    if (this.isEcoleFoot) {
                        return this.autorisationTransportDirigeants !== null
                            && this.autorisationTransportParents !== null;
                    }
                    return true;
                case 4:
                    return this.hasRead && this.signatureData !== '';
                case 5:
                    return this.paymentMode !== '';
                default:
                    return false;
            }
        },

        next() {
            if (this.canGoNext && this.step < 5) {
                this.step++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        prev() {
            if (this.step > 1) {
                this.step--;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        initSignaturePad() {
            const canvas = this.$refs.signatureCanvas;
            if (!canvas || this.signaturePad) return;

            // Scaling pour les écrans Retina / haute densité
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

        clearSignature() {
            if (this.signaturePad) {
                this.signaturePad.clear();
                this.signatureData = '';
            }
        },
    };
}
