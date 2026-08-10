<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Test fonctionnel du parcours de complétion des autorisations manquantes
 * (page publique /inscription/{uuid}/completer).
 */
final class InscriptionCompletionControllerTest extends WebTestCase
{
    public function testPageAfficheUniquementLesAutorisationsManquantes(): void
    {
        $client = static::createClient();
        $uuid = $this->createSeniorPhotoManquante();

        $client->request('GET', '/inscription/' . $uuid . '/completer');
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Droit à l\'image', $html);
        // Un sénior n'a pas d'autorisation de transport : elle ne doit pas apparaître.
        self::assertStringNotContainsString('Transport par les dirigeants', $html);
    }

    public function testSoumissionEnregistreLaReponseEtConsommeLeLien(): void
    {
        $client = static::createClient();
        $uuid = $this->createSeniorPhotoManquante();

        $crawler = $client->request('GET', '/inscription/' . $uuid . '/completer');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/inscription/' . $uuid . '/completer', [
            '_token' => $token,
            'autorisation_photo' => '1',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Merci', (string) $client->getResponse()->getContent());

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $licencie = $em->find(Licencie::class, Uuid::fromString($uuid));

        self::assertTrue($licencie->getDossierClub()->getAutorisationPhoto());
        self::assertFalse($licencie->isFormTokenValid(), 'Le lien doit être consommé après soumission.');
    }

    public function testLienExpireAfficheLaPageExpiree(): void
    {
        $client = static::createClient();
        $uuid = $this->createSeniorPhotoManquante(tokenExpire: true);

        $client->request('GET', '/inscription/' . $uuid . '/completer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Lien expiré', (string) $client->getResponse()->getContent());
    }

    /** Persiste un sénior dont le dossier est complété mais l'autorisation photo laissée vide. */
    private function createSeniorPhotoManquante(bool $tokenExpire = false): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setCategory($category)
            ->setSeason($season)
            ->setFormTokenExpiresAt(new \DateTimeImmutable($tokenExpire ? '-1 day' : '+30 days'));

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED)
            ->setFormCompletedAt(new \DateTimeImmutable());

        $em->persist($season);
        $em->persist($category);
        $em->persist($licencie);
        $em->persist($dossier);
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        // Détache : le contrôleur doit recharger depuis la base (hydrate la relation inverse).
        $em->clear();

        return $uuid;
    }
}
