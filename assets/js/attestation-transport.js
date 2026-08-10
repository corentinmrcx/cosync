import { creerPad } from './signature-pad.js';

/**
 * Attestation de transport bénévole : les mêmes champs, la même validation et le même
 * pad de signature dans les trois parcours (licencié, dirigeant, complétion).
 *
 * La règle « date de contrôle technique non future » est le miroir côté client de
 * AttestationTransportRequestFactory : le serveur reste seul juge, le client évite
 * simplement au signataire un aller-retour inutile.
 *
 * Référence x-ref attendue dans le template : signatureCanvasAttestation.
 */
export function attestationTransport() {
    return {
        nomConducteur: '',
        prenomConducteur: '',
        numPermis: '',
        assuranceNomAdresse: '',
        dateCT: '',
        vehiculeNeuf: false,
        engagementAttestation: false,
        signatureDataAttestation: '',
        signaturePadAttestation: null,

        /** Vrai si la date saisie est strictement dans le futur (aujourd'hui reste autorisé). */
        get dateCTFuture() {
            if (this.dateCT === '') return false;

            const d = new Date();
            const aujourdhui = d.getFullYear() + '-'
                + String(d.getMonth() + 1).padStart(2, '0') + '-'
                + String(d.getDate()).padStart(2, '0');

            return this.dateCT > aujourdhui;
        },

        /** Un véhicule neuf n'a pas encore de contrôle technique : la date n'est alors pas exigée. */
        get attestationValide() {
            return this.nomConducteur !== ''
                && this.prenomConducteur !== ''
                && this.numPermis !== ''
                && this.assuranceNomAdresse !== ''
                && (this.vehiculeNeuf || (this.dateCT !== '' && !this.dateCTFuture))
                && this.engagementAttestation
                && this.signatureDataAttestation !== '';
        },

        initAttestationSignaturePad() {
            const canvas = this.$refs.signatureCanvasAttestation;
            if (!canvas || this.signaturePadAttestation) return;

            this.signaturePadAttestation = creerPad(canvas, (data) => {
                this.signatureDataAttestation = data;
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
