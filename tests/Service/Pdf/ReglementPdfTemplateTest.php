<?php declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Entity\Season;
use App\Enum\ReglementAudience;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Le PDF du règlement est un template unique servant deux documents. On vérifie
 * ici qu'il rend bien le texte du destinataire, et pas celui de l'autre : c'est
 * ce document qui part sur Drive et fait foi.
 */
final class ReglementPdfTemplateTest extends KernelTestCase
{
    private const TEXTE_JOUEURS = '<p>Engagement reserve aux joueurs.</p>';
    private const TEXTE_DIRIGEANTS = '<p>Engagement reserve aux dirigeants.</p>';

    public function testLeReglementDesJoueursRendLeTexteEtLeTitreJoueurs(): void
    {
        $html = $this->render(ReglementAudience::LICENCIE);

        self::assertStringContainsString('<h1>Règlement intérieur</h1>', $html);
        self::assertStringContainsString(self::TEXTE_JOUEURS, $html);
        self::assertStringNotContainsString(self::TEXTE_DIRIGEANTS, $html);
        self::assertStringContainsString('accepté le règlement intérieur du Foyer de Soudron', $html);
    }

    public function testLeReglementDesDirigeantsRendLeTexteEtLeTitreDirigeants(): void
    {
        $html = $this->render(ReglementAudience::DIRIGEANT);

        self::assertStringContainsString('<h1>Règlement intérieur des dirigeants</h1>', $html);
        self::assertStringContainsString(self::TEXTE_DIRIGEANTS, $html);
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
        self::assertStringContainsString('accepté le règlement intérieur des dirigeants du Foyer de Soudron', $html);
    }

    public function testUnReglementNonRedigeLeDitPlutotQueDeRendreLAutre(): void
    {
        $html = $this->render(ReglementAudience::DIRIGEANT, avecReglementDirigeant: false);

        self::assertStringContainsString('Aucun règlement défini pour cette saison.', $html);
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
    }

    /** Les deux destinataires écrivent dans des fichiers distincts (cas du dirigeant-joueur). */
    public function testLesDeuxDocumentsNePeuventPasSEcraser(): void
    {
        self::assertNotSame(
            ReglementAudience::LICENCIE->fileSuffix(),
            ReglementAudience::DIRIGEANT->fileSuffix(),
        );
    }

    private function render(ReglementAudience $audience, bool $avecReglementDirigeant = true): string
    {
        self::bootKernel();

        // Les globales Twig du projet lisent la saison courante en session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $season = (new Season())
            ->setLabel('2025-2026')
            ->setReglementText(self::TEXTE_JOUEURS)
            ->setReglementDirigeantText($avecReglementDirigeant ? self::TEXTE_DIRIGEANTS : null);

        return self::getContainer()->get(Environment::class)->render('pdf/reglement_signe.html.twig', [
            'prenom'           => 'Kevin',
            'nom'              => 'MARTIN',
            'season'           => $season,
            'documentTitle'    => $audience->documentTitle(),
            'documentLabel'    => $audience->documentLabel(),
            'reglementHtml'    => $audience->textOf($season),
            'signatureDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'signedAt'         => new \DateTimeImmutable('2026-08-01'),
            'logoDataUrl'      => 'data:image/png;base64,iVBORw0KGgo=',
            'foyerLogoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'previewMode'      => false,
        ]);
    }
}
