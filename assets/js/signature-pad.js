/**
 * Pad de signature unique, pour un formulaire qui n'a qu'un document à signer.
 * S'intègre dans un composant Alpine via le spread (`...singleSignaturePad()`).
 *
 * Les parcours licencié et dirigeant, eux, peuvent avoir plusieurs documents et
 * utilisent documentSignatures(), qui indexe le même comportement par document.
 *
 * Référence x-ref attendue dans le template : signatureCanvas.
 */
export function singleSignaturePad() {
    return {
        hasRead: false,
        signatureData: '',
        signaturePad: null,

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
