<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\AttestationPaiement;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Referentiel\SignatureCachetStorage;

/**
 * Rend l'attestation de paiement d'une licence.
 *
 * Le document se rend à l'identique qu'il soit émis, prévisualisé avant émission ou
 * retéléchargé des mois plus tard : tout ce qu'il affirme est porté par l'attestation
 * elle-même, jamais relu des paiements — qui, eux, peuvent avoir été corrigés depuis.
 *
 * Seuls l'identité de l'association et le paraphe scanné sont lus en direct. C'est
 * assumé : si le club déménage, c'est sa nouvelle adresse qui est la bonne, et
 * l'exemplaire qui fait foi reste celui archivé sur Drive.
 */
final class AttestationPaiementPdfService
{
    private const TEMPLATE = 'pdf/attestation_paiement.html.twig';

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
        private readonly PdfStorage $storage,
        private readonly ClubSettingsService $clubSettings,
        private readonly SignatureCachetStorage $signatures,
    ) {}

    /** Écrit le PDF dans var/pdfs/ et retourne son chemin local — il y attend son archivage Drive. */
    public function generer(AttestationPaiement $attestation): string
    {
        return $this->storage->ecrire(
            $attestation->getUuid() . '_attestation_paiement.pdf',
            $this->rendu($attestation),
        );
    }

    /** @return string contenu binaire du PDF */
    public function rendu(AttestationPaiement $attestation, bool $apercu = false): string
    {
        $club = $this->clubSettings->get();

        return $this->renderer->render(self::TEMPLATE, [
            'attestation' => $attestation,
            'club' => $club,
            'signatureDataUrl' => $this->signatures->dataUrl($club->getSignatureCachetFichier()),
            'logoDataUrl' => $this->assets->logoClub(),
            'foyerLogoDataUrl' => $this->assets->logoFoyer(),
            'previewMode' => $apercu,
        ]);
    }
}
