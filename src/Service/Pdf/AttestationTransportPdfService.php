<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\DTO\AttestationTransportData;

/**
 * Attestation de transport bénévole. Réutilisable pour licenciés et dirigeants.
 */
final class AttestationTransportPdfService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
        private readonly PdfStorage $storage,
    ) {}

    /** @return string chemin absolu du PDF écrit */
    public function generate(AttestationTransportData $data, string $nom, string $prenom, string $seasonLabel): string
    {
        $pdf = $this->renderer->render('pdf/attestation_transport.html.twig', [
            'data' => $data,
            'nom' => $nom,
            'prenom' => $prenom,
            'seasonLabel' => $seasonLabel,
            'signedAt' => new \DateTimeImmutable(),
            'logoDataUrl' => $this->assets->logoClub(),
            'foyerLogoDataUrl' => $this->assets->logoFoyer(),
        ]);

        return $this->storage->ecrire($this->nomFichier($data), $pdf);
    }

    /**
     * Un conducteur peut signer plusieurs attestations dans la saison (deux enfants,
     * une correction) : l'identifiant unique évite qu'elles s'écrasent.
     */
    private function nomFichier(AttestationTransportData $data): string
    {
        $identite = preg_replace('/[^A-Za-z0-9_]/', '', $data->nomConducteur . '_' . $data->prenomConducteur);

        return sprintf('TRANSPORT_%s_%s.pdf', $identite, uniqid());
    }
}
