<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les licenciés et les dirigeants signent deux documents distincts. Chaque
 * parcours public ne doit afficher que le règlement qui le concerne — la
 * régression à couvrir est celle d'origine, où les dirigeants se voyaient
 * présenter le règlement des joueurs.
 */
final class ReglementParParcoursTest extends WebTestCase
{
    private const TEXTE_JOUEURS = '<p>Engagement reserve aux joueurs du club.</p>';
    private const TEXTE_DIRIGEANTS = '<p>Engagement reserve aux dirigeants du club.</p>';

    public function testLeParcoursDirigeantAfficheLeReglementDesDirigeants(): void
    {
        $client = static::createClient();
        $uuid   = $this->createDirigeant();

        $client->request('GET', '/dirigeant/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Règlement intérieur des dirigeants', $html);
        self::assertStringContainsString(self::TEXTE_DIRIGEANTS, $html);
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
    }

    public function testLeParcoursLicencieAfficheToujoursLeReglementDesJoueurs(): void
    {
        $client = static::createClient();
        $uuid   = $this->createLicencie();

        $client->request('GET', '/inscription/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TEXTE_JOUEURS, $html);
        self::assertStringNotContainsString(self::TEXTE_DIRIGEANTS, $html);
    }

    public function testUnReglementDirigeantsNonRedigeInviteAContacterLeClub(): void
    {
        $client = static::createClient();
        $uuid   = $this->createDirigeant(avecReglementDirigeant: false);

        $client->request('GET', '/dirigeant/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun règlement n\'a encore été défini', $html);
        // Le règlement des joueurs ne doit surtout pas servir de repli.
        self::assertStringNotContainsString(self::TEXTE_JOUEURS, $html);
    }

    private function createDirigeant(bool $avecReglementDirigeant = true): string
    {
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $season = $this->makeSeason($avecReglementDirigeant);

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setTailleHaut('L')->setTailleBas('M')->setPointure('42')
            ->setAutorisationPhoto(true)
            ->setVolontaireTransport(false)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        $em->persist($season);
        $em->persist($dirigeant);
        $em->flush();

        $uuid = (string) $dirigeant->getUuid();
        $em->clear();

        return $uuid;
    }

    private function createLicencie(): string
    {
        $em       = self::getContainer()->get(EntityManagerInterface::class);
        $season   = $this->makeSeason();
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setCategory($category)
            ->setSeason($season)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        $em->persist($season);
        $em->persist($category);
        $em->persist($licencie);
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $uuid;
    }

    private function makeSeason(bool $avecReglementDirigeant = true): Season
    {
        return (new Season())
            ->setLabel('2025-2026')
            ->setCotisationDefaut(85)
            ->setReglementText(self::TEXTE_JOUEURS)
            ->setReglementDirigeantText($avecReglementDirigeant ? self::TEXTE_DIRIGEANTS : null);
    }
}
