<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Boutique du club sur la page de confirmation d'inscription.
 *
 * Le lien est facultatif : sans réglage, la page ne doit rien annoncer du tout plutôt
 * qu'afficher un bloc vide ou un lien mort.
 */
final class BoutiqueConfirmationTest extends WebTestCase
{
    private const URL = 'https://www.helloasso.com/associations/fc-soudron/boutiques/boutique-du-club';

    public function testLeLienEstAfficheQuandLaBoutiqueEstConfiguree(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);
        $this->configurerBoutique(self::URL);

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/confirmation');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.inscription-boutique a[href="' . self::URL . '"]'));
    }

    public function testSansBoutiqueConfigureeRienNEstAnnonce(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);
        $this->configurerBoutique(null);

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/confirmation');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.inscription-boutique'));
    }

    /**
     * Le club prépare son lien avant d'ouvrir sa boutique : tant qu'elle est fermée, la
     * page de confirmation n'en dit rien.
     */
    public function testBoutiqueFermeeRienNEstAnnonce(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $licencie = $this->createLicencie($em);
        $this->configurerBoutique(self::URL, ouverte: false);

        $crawler = $client->request('GET', '/inscription/' . $licencie->getUuid() . '/confirmation');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.inscription-boutique'));
    }

    /* ── Outils ── */

    private function configurerBoutique(?string $url, bool $ouverte = true): void
    {
        $settings = static::getContainer()->get(ClubSettingsService::class);
        $settings->get()->setBoutiqueUrl($url)->setBoutiqueOuverte($ouverte);
        $settings->enregistrer();
    }

    private function createLicencie(EntityManagerInterface $em): Licencie
    {
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('1995-04-12'))
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED)
            ->setFormCompletedAt(new \DateTimeImmutable());

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $em->getRepository(Licencie::class)->find(Uuid::fromString($uuid));
    }
}
