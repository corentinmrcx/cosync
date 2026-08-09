<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\Dirigeant;
use App\Entity\Season;

/**
 * Attestation individuelle de remise de clés du club house, signée par un détenteur.
 */
final class AttestationClePdfService
{
    private const TEMPLATE = 'pdf/attestation_cle_signee.html.twig';

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
        private readonly PdfStorage $storage,
    ) {}

    public function generateSignee(
        Dirigeant $dirigeant,
        string $signatureDataUrl,
        int $nbCles,
        ?\DateTimeImmutable $remiseLe = null,
    ): string {
        return $this->storage->ecrire(
            $dirigeant->getUuid() . '_attestation_cle.pdf',
            $this->renderer->render(self::TEMPLATE, $this->contexte(
                $dirigeant->getPrenom(),
                $dirigeant->getNom(),
                $dirigeant->getSeason(),
                $nbCles,
                $remiseLe,
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
