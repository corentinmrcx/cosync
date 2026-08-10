<?php declare(strict_types=1);

namespace App\Service\Inscription;

use App\DTO\AttestationTransportData;
use App\Service\Document\SignatureImageValidator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Construit et valide l'attestation de transport bénévole depuis une requête publique.
 * Partagé par le formulaire licencié, le formulaire dirigeant et la complétion.
 * Retourne null si les données sont incomplètes ou invalides.
 */
final class AttestationTransportRequestFactory
{
    public function __construct(
        private readonly SignatureImageValidator $signatureValidator,
    ) {}

    public function fromRequest(Request $request): ?AttestationTransportData
    {
        $nomConducteur = trim($request->request->get('attestation_nom_conducteur', ''));
        $prenomConducteur = trim($request->request->get('attestation_prenom_conducteur', ''));
        $numPermis = $request->request->get('attestation_num_permis', '');
        $assurance = $request->request->get('attestation_assurance', '');
        $dateCTRaw = $request->request->get('attestation_date_ct', '');
        $sigAttest = $request->request->get('attestation_signature_data', '');
        $engagement = $request->request->get('attestation_engagement') === '1';
        $vehiculeNeuf = $request->request->get('attestation_vehicule_neuf') === '1';

        // La date de contrôle technique n'est pas exigée pour un véhicule neuf
        if ($nomConducteur === '' || $prenomConducteur === ''
            || $numPermis === '' || $assurance === '' || $sigAttest === ''
            || (!$vehiculeNeuf && $dateCTRaw === '')) {
            return null;
        }

        if (!$this->signatureValidator->estValide($sigAttest)) {
            return null;
        }

        $dateCT = null;
        if (!$vehiculeNeuf) {
            try {
                $dateCT = new \DateTimeImmutable($dateCTRaw);
            } catch (\Exception) {
                return null;
            }

            // Refuser une date de contrôle technique dans le futur
            if ($dateCT > new \DateTimeImmutable('today')) {
                return null;
            }
        }

        return new AttestationTransportData(
            nomConducteur: $nomConducteur,
            prenomConducteur: $prenomConducteur,
            numPermis: $numPermis,
            assuranceNomAdresse: $assurance,
            dateCT: $dateCT,
            vehiculeNeuf: $vehiculeNeuf,
            engagementPris: $engagement,
            signatureData: $sigAttest,
        );
    }
}
