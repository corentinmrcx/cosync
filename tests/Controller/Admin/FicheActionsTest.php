<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'en-tête de la fiche licencié : une action mise en avant, les autres dans le menu « ⋯ ».
 *
 * Le tri lui-même est couvert par FicheActionsResolverTest ; ici on vérifie que les deux
 * contextes de rendu (bouton d'en-tête, ligne de menu) sortent bien du même include — une
 * action perdue en route ne se verrait nulle part ailleurs.
 */
final class FicheActionsTest extends WebTestCase
{
    public function testLEnTeteNExposeQuUneActionEtRangeLeResteDansLeMenu(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(LicenceStatus::A_VALIDER_FFF);

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $uuid);

        $miseEnAvant = $crawler->filter('.licencie-show-header-actions > form');
        self::assertCount(1, $miseEnAvant, 'Une seule action mise en avant.');
        self::assertStringContainsString('Valider sur FootClubs', $miseEnAvant->text());

        $menu = $crawler->filter('.fiche-menu-panneau');
        self::assertCount(1, $menu, 'Le menu existe dès qu\'il reste des actions.');
        self::assertStringContainsString('Modifier', $menu->text());
    }

    /** Sans adresse, le motif prend la place du bouton — et rien ne part dans le menu. */
    public function testSansEmailLeMotifSAfficheAlaPlaceDeLAction(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $uuid = $this->seedLicencie(LicenceStatus::IMPORTED, email: null);

        $crawler = $client->request('GET', '/admin/effectif/joueurs/' . $uuid);

        self::assertStringContainsString('Pas d\'email renseigné', $crawler->filter('.licencie-show-header-actions')->text());
        self::assertCount(0, $crawler->filter('form[action$="/send-link"]'));
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('admin-actions@example.test')->setPassword('x');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    private function seedLicencie(LicenceStatus $statut, ?string $email = 'kevin.martin@example.test'): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = $em->getRepository(Season::class)->findOneBy(['label' => '2025-2026'])
            ?? (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = $em->getRepository(Category::class)->findOneBy(['code' => 'SENIOR'])
            ?? (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail($email)
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus($statut)
            ->setFormCompletedAt($statut === LicenceStatus::IMPORTED ? null : new \DateTimeImmutable());

        // Autorisations répondues : sans cela « Compléter les autorisations » serait l'étape
        // du moment, et c'est bien elle que la fiche mettrait en avant.
        $dossier->setAutorisationPhoto(true)->setVolontaireTransport(false);

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $uuid = (string) $licencie->getUuid();
        $em->clear();

        return $uuid;
    }
}
