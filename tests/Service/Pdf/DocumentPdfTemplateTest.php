<?php declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Enum\DocumentCible;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Le PDF est un template unique servant tous les documents signables. On vérifie
 * ici qu'il rend le texte et les libellés du document demandé : c'est ce document
 * qui part sur Drive et fait foi.
 */
final class DocumentPdfTemplateTest extends KernelTestCase
{
    private const TEXTE_JOUEURS = '<p>Engagement reserve aux joueurs.</p>';
    private const TEXTE_DIRIGEANTS = '<p>Engagement reserve aux dirigeants.</p>';

    public function testLeReglementDesJoueursRendLeTexteEtLeTitreJoueurs(): void
    {
        $html = $this->render($this->documentJoueurs());

        self::assertStringContainsString('<h1>Règlement intérieur</h1>', $html);
        self::assertStringContainsString(self::TEXTE_JOUEURS, $html);
        self::assertStringNotContainsString(self::TEXTE_DIRIGEANTS, $html);
        self::assertStringContainsString('accepté le règlement intérieur du Foyer de Soudron', $html);
    }

    public function testLeReglementDesDirigeantsRendLeTexteEtLeTitreDirigeants(): void
    {
        $html = $this->render($this->documentDirigeants());

        self::assertStringContainsString('<h1>Règlement intérieur des dirigeants</h1>', $html);
        self::assertStringContainsString(self::TEXTE_DIRIGEANTS, $html);
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
        self::assertStringContainsString('accepté le règlement intérieur des dirigeants du Foyer de Soudron', $html);
    }

    public function testUneCharteRendSonPropreTitreEtSonPropreLibelle(): void
    {
        $document = $this->makeDocument(
            'charte_communication',
            'Charte d\'engagement — Communication / Réseaux sociaux',
            'la charte d\'engagement communication du Foyer de Soudron',
            '<p>Je m engage a representer le club avec mesure.</p>',
            DocumentCible::DIRIGEANT,
        );

        $html = $this->render($document);

        // Le titre et le libellé traversent l'échappement Twig : on compare sur la forme rendue.
        self::assertStringContainsString('Charte d&#039;engagement', $html);
        self::assertStringContainsString('Je m engage a representer le club avec mesure.', $html);
        self::assertStringContainsString('accepté la charte d&#039;engagement communication du Foyer de Soudron', $html);
    }

    public function testUnDocumentNonRedigeLeDitPlutotQueDeRendreLAutre(): void
    {
        $html = $this->render($this->documentDirigeants(contenuHtml: null));

        // Littéral du template : Twig le considère sûr, il sort tel quel.
        self::assertStringContainsString('Ce document n\'a pas encore été rédigé', $html);
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
    }

    private function documentJoueurs(): DocumentSignable
    {
        return $this->makeDocument(
            'reglement_licencie',
            'Règlement intérieur',
            'le règlement intérieur du Foyer de Soudron',
            self::TEXTE_JOUEURS,
            DocumentCible::LICENCIE,
        );
    }

    private function documentDirigeants(?string $contenuHtml = self::TEXTE_DIRIGEANTS): DocumentSignable
    {
        return $this->makeDocument(
            'reglement_dirigeant',
            'Règlement intérieur des dirigeants',
            'le règlement intérieur des dirigeants du Foyer de Soudron',
            $contenuHtml,
            DocumentCible::DIRIGEANT,
        );
    }

    private function makeDocument(
        string $code,
        string $titre,
        string $libelle,
        ?string $contenuHtml,
        DocumentCible $cible,
    ): DocumentSignable {
        return (new DocumentSignable())
            ->setSeason((new Season())->setLabel('2025-2026'))
            ->setCode($code)
            ->setTitre($titre)
            ->setLibelle($libelle)
            ->setContenuHtml($contenuHtml)
            ->setCible($cible)
            ->setDriveSegments(['Documents signés', $titre])
            ->setFilePrefix($code);
    }

    private function render(DocumentSignable $document): string
    {
        self::bootKernel();

        // Les globales Twig du projet lisent la saison courante en session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        return self::getContainer()->get(Environment::class)->render('pdf/document_signe.html.twig', [
            'prenom' => 'Kevin',
            'nom' => 'MARTIN',
            'season' => $document->getSeason(),
            'documentTitle' => $document->getTitre(),
            'documentLabel' => $document->getLibelle(),
            'reglementHtml' => $document->getContenuHtml(),
            'signatureDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'signedAt' => new \DateTimeImmutable('2026-08-01'),
            'logoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'foyerLogoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'previewMode' => false,
        ]);
    }
}
