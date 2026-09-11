import { assembler } from './composant.js';
import { singleSignaturePad } from './signature-pad.js';

/**
 * Formulaire public de signature de l'attestation de remise de clés. Mono-page.
 *
 * Quand le club a rédigé un règlement (`aReglement`), la case « J'atteste » reste
 * bloquée tant qu'il n'a pas été déroulé jusqu'en bas — même barrière que les
 * formulaires licencié et dirigeant. Sans règlement, il n'y a rien à lire : la
 * case est libre d'emblée.
 */
export function attestationCleForm({ aReglement = false } = {}) {
    return assembler(singleSignaturePad(), {
        submitting: false,
        scrolled: !aReglement,

        init() {
            // Un règlement qui tient sans ascenseur est réputé lu dès l'affichage.
            this.$nextTick(() => this.debloquerSiReglementCourt());

            this.$watch('hasRead', (value) => {
                if (value === true) {
                    window.requestAnimationFrame(() => this.initSignaturePad());
                }
            });
        },

        debloquerSiReglementCourt() {
            const el = this.$refs.reglement;
            if (el && el.scrollHeight <= el.clientHeight + 2) {
                this.scrolled = true;
            }
        },

        onReglementScroll(event) {
            if (this.scrolled) return;

            const el = event.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
                this.scrolled = true;
            }
        },

        get canSubmit() {
            return this.scrolled && this.hasRead && this.signatureData !== '';
        },
    });
}
