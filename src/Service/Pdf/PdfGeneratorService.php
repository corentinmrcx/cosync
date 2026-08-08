<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\DocumentSignable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class PdfGeneratorService
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /**
     * Génère le PDF d'un document signé et le sauvegarde dans var/pdfs/.
     * Retourne le chemin absolu du fichier généré.
     *
     * Le nom de fichier combine l'identifiant du signataire et le code du document :
     * une personne qui signe plusieurs documents (le règlement dirigeants et une charte,
     * par exemple) ne peut pas en écraser un avec l'autre.
     */
    public function generateSignedDocument(
        DocumentSignable $document,
        string $prenom,
        string $nom,
        string $fileKey,
        string $signatureDataUrl,
    ): string {
        $html = $this->renderDocumentHtml($document, $prenom, $nom, $signatureDataUrl);

        $dir = $this->projectDir . '/var/pdfs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $fileKey . '_' . $document->getCode() . '.pdf';
        file_put_contents($path, $this->renderPdf($html));

        return $path;
    }

    /** Rendu d'aperçu pour l'administration : contenu binaire, jamais écrit sur disque. */
    public function generatePreview(DocumentSignable $document): string
    {
        return $this->renderPdf($this->renderDocumentHtml(
            $document,
            'Prénom',
            'NOM',
            '',
            previewMode: true,
        ));
    }

    private function renderDocumentHtml(
        DocumentSignable $document,
        string $prenom,
        string $nom,
        string $signatureDataUrl,
        bool $previewMode = false,
    ): string {
        return $this->twig->render('pdf/document_signe.html.twig', [
            'prenom' => $prenom,
            'nom' => $nom,
            'season' => $document->getSeason(),
            'documentTitle' => $document->getTitre(),
            'documentLabel' => $document->getLibelle(),
            'reglementHtml' => $document->getContenuHtml(),
            'signatureDataUrl' => $signatureDataUrl,
            'signedAt' => new \DateTimeImmutable(),
            'logoDataUrl' => $this->encodeImage($this->projectDir . '/public/images/logo/logo.png'),
            'foyerLogoDataUrl' => $this->encodeImage($this->projectDir . '/public/images/logo/foyerDeSoudron.png'),
            'previewMode' => $previewMode,
        ]);
    }

    private function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function encodeImage(string $path): string
    {
        return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
    }
}
