<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Dirigeant;
use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pages légales publiques. Le piège de ces routes est l'access_control :
 * la règle finale « ^/ → ROLE_USER » les renverrait sur le login sans
 * déclaration PUBLIC_ACCESS explicite.
 */
final class LegalControllerTest extends WebTestCase
{
    public function testPolitiqueConfidentialiteAccessibleSansAuthentification(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/politique-de-confidentialite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Politique de confidentialité');

        // Mentions dont l'absence rendrait la page non conforme à l'art. 13 RGPD.
        $texte = $crawler->filter('.legal-content')->text();
        self::assertStringContainsString('contact@codepp.fr', $texte);
        self::assertStringContainsString('CNIL', $texte);
    }

    public function testMentionsLegalesAccessiblesSansAuthentification(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/mentions-legales');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mentions légales');

        // Les mentions renvoient vers la politique de confidentialité.
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/politique-de-confidentialite"]')->count(),
        );
    }

    public function testLesPagesLegalesSontAtteignablesDepuisUnFormulairePublic(): void
    {
        $client = static::createClient();
        $uuid = $this->createDirigeant();

        $crawler = $client->request('GET', '/dirigeant/' . $uuid);

        self::assertResponseIsSuccessful();

        // Footer partagé : les deux pages doivent être atteignables depuis le parcours réel.
        self::assertGreaterThan(
            0,
            $crawler->filter('.footer a[href="/politique-de-confidentialite"]')->count(),
            'Le footer doit exposer le lien vers la politique de confidentialité.',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('.footer a[href="/mentions-legales"]')->count(),
            'Le footer doit exposer le lien vers les mentions légales.',
        );

        // Information au moment de la collecte, dans le formulaire lui-même.
        self::assertGreaterThan(
            0,
            $crawler->filter('.inscription-rgpd-note')->count(),
            "L'encart d'information RGPD doit être affiché à l'entrée du formulaire.",
        );
    }

    private function createDirigeant(): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        $em->persist($season);
        $em->persist($dirigeant);
        $em->flush();

        $uuid = (string) $dirigeant->getUuid();
        $em->clear();

        return $uuid;
    }
}
