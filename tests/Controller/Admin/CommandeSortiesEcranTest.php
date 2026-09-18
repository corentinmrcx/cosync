<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\DotationBesoin;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\User;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les sorties de l'écran des commandes : une seule en clair, les autres au menu « ⋯ ».
 *
 * La liste de flocage vivait sur le suivi des dotations. Elle part chez le floqueur au moment
 * où l'on commande — `DotationFlocageService::changer()` refuse d'ailleurs de corriger un texte
 * dès la préparation — et non quand on remplit les sacs. Le suivi, lui, ne sert qu'à remettre.
 */
final class CommandeSortiesEcranTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;

    public function testLesSortiesDeLEcranTiennentDansLEnTeteUneSeuleEnClair(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeBesoin();
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/commandes');

        self::assertResponseIsSuccessful();

        $entete = $crawler->filter('.dot-header-actions');
        self::assertCount(1, $entete->filter('.btn-primary'), 'L\'écran ne met en avant qu\'une action.');
        self::assertStringContainsString('Générer les bons de commande', $entete->filter('.btn-primary')->text());

        $menu = $entete->filter('.fiche-menu-panneau');
        self::assertStringContainsString('Justificatif de commande', $menu->text());
        self::assertStringContainsString('Liste de flocage', $menu->text());
        self::assertCount(1, $menu->filter('a[href="/admin/dotations/flocage"]'));
    }

    /** L'écran de flocage ne sert qu'à se relire : sa seule action est d'en éditer la feuille. */
    public function testLaFeuilleDuFloqueurSEditeDepuisLEcranDeFlocage(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/dotations/flocage');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action="/admin/dotations/flocage/pdf"]'), 'Rien à floquer, rien à éditer.');

        $this->makeBesoin()->setPersonnalisation('ROBERT');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/flocage');
        $form = $crawler->filter('form[action="/admin/dotations/flocage/pdf"]');

        self::assertCount(1, $form);
        $client->request('POST', '/admin/dotations/flocage/pdf', [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    public function testLeSuiviDesDotationsNeRenvoiePlusAuFlocage(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/admin/dotations/flocage"]'));
    }

    private function makeBesoin(): DotationBesoin
    {
        $item = (new StockItem())
            ->setNom('T-shirt')->setMarque('Erima')->setCouleur('Jaune')
            ->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->persist($item);

        $besoin = (new DotationBesoin())->setSeason($this->season)->setStockItem($item)->setTaille('M')->setQuantite(1);
        $this->em->persist($besoin);

        return $besoin;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-commandes-sorties@example.com')->setPassword('x');

        $this->em->persist($this->season);
        $this->em->persist($user);
        $this->em->flush();

        $user->setSelectedSeason($this->season);
        $this->em->flush();

        $client->loginUser($user);
    }
}
