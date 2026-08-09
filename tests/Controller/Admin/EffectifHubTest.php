<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Hub Effectif : compteurs de population et liste « À faire » pointant vers les
 * listes filtrées.
 */
final class EffectifHubTest extends WebTestCase
{
    private ?Season $season = null;

    public function testLesCompteursRefletentLesDossiersDeLaSaison(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->creerEffectif();

        $crawler = $client->request('GET', '/admin/effectif');

        self::assertResponseIsSuccessful();

        $valeurs = $crawler->filter('.stat-card-value')->each(static fn ($n) => trim($n->text()));
        self::assertSame('3', $valeurs[0], '3 joueurs au total');
        self::assertSame('1', $valeurs[1], '1 dirigeant');
        self::assertSame('1', $valeurs[2], '1 formulaire en attente (lien jamais envoyé)');
        self::assertSame('1', $valeurs[3], '1 paiement à encaisser');
    }

    public function testLaListeAFairePointeVersLesListesFiltrees(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $this->creerEffectif();

        $client->request('GET', '/admin/effectif');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('inscription jamais envoyé', $html);
        self::assertStringContainsString('/admin/effectif/joueurs?status=imported', $html);
        self::assertStringContainsString('dossier complet en attente de paiement', $html);
        self::assertStringContainsString('/admin/effectif/joueurs?status=form_completed', $html);
    }

    public function testLeHubProposeLesQuatreAccesRapides(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/effectif');

        self::assertResponseIsSuccessful();
        $titres = $crawler->filter('.quicklink-title')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Joueurs', 'Dirigeants', 'Import FootClubs', 'Documents à signer'], $titres);
    }

    /* ── Outils ── */

    /** 3 joueurs (importé / formulaire complété / validé) + 1 dirigeant. */
    private function creerEffectif(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);
        $em->persist($category);

        foreach ([
            LicenceStatus::IMPORTED,
            LicenceStatus::FORM_COMPLETED,
            LicenceStatus::VALIDATED,
        ] as $i => $status) {
            $licencie = (new Licencie())
                ->setNom('JOUEUR' . $i)
                ->setPrenom('Test')
                ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
                ->setCategory($category)
                ->setSeason($this->season);

            $dossier = (new DossierClub())
                ->setLicencie($licencie)
                ->setStatus($status);

            $em->persist($licencie);
            $em->persist($dossier);
        }

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')->setPrenom('Kevin')->setSeason($this->season);
        $em->persist($dirigeant);

        $em->flush();
        $em->clear();
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setEmail('admin-effectif@example.test')->setPassword('x');
        $user->setSelectedSeason($this->season);

        $em->persist($this->season);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }
}
