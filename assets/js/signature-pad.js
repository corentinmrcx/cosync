/**
 * Création d'un pad de signature sur un canvas.
 *
 * Le canvas est redimensionné selon la densité de l'écran avant l'initialisation :
 * sans cela, le tracé est flou sur mobile — et c'est sur mobile que les licenciés signent.
 */
export function creerPad(canvas, onTrace) {
    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    canvas.width = canvas.offsetWidth * ratio;
    canvas.height = canvas.offsetHeight * ratio;
    canvas.getContext('2d').scale(ratio, ratio);

    const pad = new SignaturePad(canvas, {
        backgroundColor: 'rgb(255, 255, 255)',
        penColor: 'rgb(0, 0, 0)',
    });

    pad.addEventListener('endStroke', () => onTrace(pad.toDataURL('image/png')));

    return pad;
}

/**
 * Pad unique, pour un formulaire qui n'a qu'un document à signer.
 * S'intègre dans un composant Alpine via le spread (`...singleSignaturePad()`).
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

            this.signaturePad = creerPad(canvas, (data) => {
                this.signatureData = data;
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
