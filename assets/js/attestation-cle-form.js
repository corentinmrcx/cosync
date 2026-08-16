import { assembler } from './composant.js';
import { singleSignaturePad } from './signature-pad.js';

/**
 * Formulaire public de signature de l'attestation de remise de clés.
 * Récépissé court, mono-page : pas de lecture imposée avant de cocher, seules
 * les parties « signature » du mixin partagé sont utilisées.
 */
export function attestationCleForm() {
    return assembler(singleSignaturePad(), {
        submitting: false,

        init() {
            this.$watch('hasRead', (value) => {
                if (value === true) {
                    window.requestAnimationFrame(() => this.initSignaturePad());
                }
            });
        },

        get canSubmit() {
            return this.hasRead && this.signatureData !== '';
        },
    });
}
