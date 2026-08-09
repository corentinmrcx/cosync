import { attestationTransport } from './attestation-transport.js';
export function completionForm({ manquants = [] }) {
    return {
        ...attestationTransport(),

        manquants,

        // Autorisations (seules celles présentes dans `manquants` sont affichées/requises)
        autorisationPhoto: null,
        autorisationAccident: null,
        autorisationTransportDirigeants: null,
        autorisationTransportParents: null,
        volontaireTransport: null,

        // Attestation transport (uniquement si volontaire === '1')

        submitting: false,

        needs(key) {
            return this.manquants.includes(key);
        },

        // L'attestation n'est demandée que si le licencié se déclare volontaire au transport
        get showAttestation() {
            return this.needs('volontaire') && this.volontaireTransport === '1';
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
                    return this.attestationValide;
                }
            }
            return true;
        },

    };
}
