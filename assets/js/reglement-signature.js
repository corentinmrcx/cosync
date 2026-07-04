/**
 * État et comportements partagés de l'étape « Règlement intérieur + signature ».
 * Utilisé à la fois par le formulaire licencié et le formulaire dirigeant.
 * S'intègre dans un composant Alpine via le spread (`...reglementSignature()`).
 *
 * Références x-ref attendues dans le template : reglementEl, signatureCanvas.
 */
export function reglementSignature() {
    return {
        reglementScrolled: false,
        hasRead: false,
        signatureData: '',
        signaturePad: null,

        onReglementScroll(event) {
            if (this.reglementScrolled) return;
            const el = event.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
                this.reglementScrolled = true;
            }
        },

        // À appeler au moment où l'étape règlement devient visible : si le règlement
        // tient sans scroll, on débloque directement la case « J'ai lu ».
        markReglementScrolledIfShort() {
            const el = this.$refs.reglementEl;
            if (el && el.scrollHeight <= el.clientHeight) {
                this.reglementScrolled = true;
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

        clearSignature() {
            if (this.signaturePad) {
                this.signaturePad.clear();
                this.signatureData = '';
            }
        },
    };
}
