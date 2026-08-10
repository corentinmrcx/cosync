<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\AttestationCle;
use App\Entity\Season;

/**
 * Attestation individuelle de remise de clés du local, signée par un détenteur.
 */
final class AttestationClePdfService
{
    private const TEMPLATE = 'pdf/attestation_cle_signee.html.twig';

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
        private readonly PdfStorage $storage,
    ) {}

    /**
     * Le nombre de clés et la date de remise sont lus sur l'attestation, où ils ont
     * été figés à la signature : régénérer le PDF ne doit jamais lui faire dire
     * autre chose que ce qu'il disait le jour de la signature.
     */
    public function generateSignee(AttestationCle $attestation, string $signatureDataUrl): string
    {
        $detenteur = $attestation->getDetenteur();

        return $this->storage->ecrire(
            $attestation->getUuid() . '_attestation_cle.pdf',
            $this->renderer->render(self::TEMPLATE, $this->contexte(
                $detenteur->getPrenom(),
                $detenteur->getNom(),
                $attestation->getSeason(),
                $attestation->getNbCles() ?? 0,
                $attestation->getRemiseLe(),
                $signatureDataUrl,
            )),
        );
    }

    /** Aperçu admin sans signature — retourne le binaire du PDF. */
    public function generatePreview(Season $season): string
    {
        return $this->renderer->render(self::TEMPLATE, $this->contexte(
            'Prénom',
            'NOM',
            $season,
            1,
            new \DateTimeImmutable(),
            '',
            previewMode: true,
        ));
    }

    /** @return array<string, mixed> */
    private function contexte(
        string $prenom,
        string $nom,
        Season $season,
        int $nbCles,
        ?\DateTimeImmutable $remiseLe,
        string $signatureDataUrl,
        bool $previewMode = false,
    ): array {
        return [
            'prenom' => $prenom,
            'nom' => $nom,
            'season' => $season,
            'nbCles' => $nbCles,
            'remiseLe' => $remiseLe,
            'signatureDataUrl' => $signatureDataUrl,
            'signedAt' => new \DateTimeImmutable(),
            'logoDataUrl' => $this->assets->logoClub(),
            'foyerLogoDataUrl' => $this->assets->logoFoyer(),
            'previewMode' => $previewMode,
        ];
    }
}
