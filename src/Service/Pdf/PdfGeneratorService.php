<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\DocumentSignable;

final class PdfGeneratorService
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly AssetEncoder $assets,
        private readonly PdfStorage $storage,
    ) {}

    /**
     * Le nom de fichier combine l'identifiant du signataire et le code du document :
     * une personne qui signe plusieurs documents (le règlement dirigeants et une charte,
     * par exemple) ne peut pas en écraser un avec l'autre.
     *
     * @return string chemin absolu du PDF écrit
     */
    public function generateSignedDocument(
        DocumentSignable $document,
        string $prenom,
        string $nom,
        string $fileKey,
        string $signatureDataUrl,
    ): string {
        return $this->storage->ecrire(
            $fileKey . '_' . $document->getCode() . '.pdf',
            $this->rendre($document, $prenom, $nom, $signatureDataUrl),
        );
    }

    /** Rendu d'aperçu pour l'administration : contenu binaire, jamais écrit sur disque. */
    public function generatePreview(DocumentSignable $document): string
    {
        return $this->rendre($document, 'Prénom', 'NOM', '', previewMode: true);
    }

    private function rendre(
        DocumentSignable $document,
        string $prenom,
        string $nom,
        string $signatureDataUrl,
        bool $previewMode = false,
    ): string {
        return $this->renderer->render('pdf/document_signe.html.twig', [
            'prenom' => $prenom,
            'nom' => $nom,
            'season' => $document->getSeason(),
            'documentTitle' => $document->getTitre(),
            'documentLabel' => $document->getLibelle(),
            'reglementHtml' => $document->getContenuHtml(),
            'signatureDataUrl' => $signatureDataUrl,
            'signedAt' => new \DateTimeImmutable(),
            'logoDataUrl' => $this->assets->logoClub(),
            'foyerLogoDataUrl' => $this->assets->logoFoyer(),
            'previewMode' => $previewMode,
        ]);
    }
}
