<?php declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\DTO\AttestationCleRecapRow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Garantie RGPD : le récapitulatif destiné à la mairie ne doit jamais porter
 * d'image de signature. Celle-ci ne vit que dans la feuille individuelle sur Drive.
 */
final class AttestationCleRecapTemplateTest extends KernelTestCase
{
    /** @param AttestationCleRecapRow[] $rows */
    private function render(array $rows): string
    {
        self::bootKernel();

        // Les globales Twig du projet lisent la saison courante en session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        return self::getContainer()->get(Environment::class)->render('pdf/attestation_cle_recap.html.twig', [
            'rows'        => $rows,
            'saisonLabel' => '2025-2026',
            'logoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'generatedAt' => new \DateTimeImmutable('2026-08-01 10:00:00'),
        ]);
    }

    public function testLeRecapitulatifNeContientAucuneImageDeSignature(): void
    {
        $html = $this->render([
            new AttestationCleRecapRow('DUPONT', 'Thomas', 2, new \DateTimeImmutable('2026-07-01')),
            new AttestationCleRecapRow('MARTIN', 'Kevin', 1, new \DateTimeImmutable('2026-07-15')),
        ]);

        self::assertSame(1, substr_count($html, '<img'), 'Seul le logo du club est une image.');
        self::assertStringNotContainsString('sign-img', $html);
        self::assertStringNotContainsString('data:image/png;base64,iVBORw0KGgoAAAANS', $html);
    }

    public function testLeRecapitulatifListeNomPrenomClesDateEtStatut(): void
    {
        $html = $this->render([
            new AttestationCleRecapRow('DUPONT', 'Thomas', 2, new \DateTimeImmutable('2026-07-01')),
            new AttestationCleRecapRow('MARTIN', 'Kevin', 1, null),
        ]);

        self::assertStringContainsString('DUPONT', $html);
        self::assertStringContainsString('Thomas', $html);
        self::assertStringContainsString('01/07/2026', $html);
        self::assertStringContainsString('>Oui<', $html);
        self::assertStringContainsString('>Non<', $html);
        self::assertStringContainsString('2 détenteurs', $html);
        self::assertStringContainsString('3 clés en circulation', $html);
        self::assertStringContainsString('1 attestation signée et à jour', $html);
    }

    public function testLeRecapitulatifGereLaListeVide(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('Aucune clé n\'est actuellement détenue', $html);
    }
}
