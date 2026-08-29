<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Corriger un mouvement saisi à la main.
 *
 * Avant, une erreur de frappe se rattrapait en supprimant la ligne pour la ressaisir : deux
 * gestes, et plus aucune trace du premier chiffre. Ici le mouvement porte la valeur juste et
 * la correction reste lisible, avec sa justification.
 */
final class StockCorrectionMouvementTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    public function testCorrigerUneQuantiteRecalculeLeStockEtLaisseUneTrace(): void
    {
        $client = $this->loginAdmin();
        $maillot = $this->maillot();
        $mouvement = $this->entree($maillot, 3, 'M');

        $client->request('POST', '/admin/stock/mouvements/' . $mouvement->getId() . '/corriger', [
            '_token' => $this->jeton($client, $mouvement->getId()),
            'quantite' => '5',
            'taille' => 'M',
            'motif' => 'Erreur de saisie, 5 reçus',
        ]);

        self::assertResponseRedirects('/admin/stock/mouvements');
        $this->em->clear();

        self::assertSame(5, $this->movementRepository()->getCurrentStockByTaille($maillot, 'M'), 'Le stock suit le mouvement corrigé.');

        $html = $client->request('GET', '/admin/stock/mouvements')->html();
        self::assertStringContainsString('Erreur de saisie, 5 reçus', $html);
        self::assertStringContainsString('3 → 5', $html, 'L\'historique dit ce que le mouvement valait avant.');
    }

    public function testCorrigerUneTailleDeplaceLeStockDUneDeclinaisonALAutre(): void
    {
        $client = $this->loginAdmin();
        $maillot = $this->maillot();
        $mouvement = $this->entree($maillot, 4, 'M');

        $client->request('POST', '/admin/stock/mouvements/' . $mouvement->getId() . '/corriger', [
            '_token' => $this->jeton($client, $mouvement->getId()),
            'quantite' => '4',
            'taille' => '128',
            'motif' => 'C\'étaient des 128',
        ]);

        $this->em->clear();
        self::assertSame(0, $this->movementRepository()->getCurrentStockByTaille($maillot, 'M'));
        self::assertSame(4, $this->movementRepository()->getCurrentStockByTaille($maillot, '128'));
        self::assertStringContainsString('taille M → 128', $client->request('GET', '/admin/stock/mouvements')->html());
    }

    public function testUneCorrectionSansJustificationEstRefusee(): void
    {
        $client = $this->loginAdmin();
        $mouvement = $this->entree($this->maillot(), 3, 'M');

        $client->request('POST', '/admin/stock/mouvements/' . $mouvement->getId() . '/corriger', [
            '_token' => $this->jeton($client, $mouvement->getId()),
            'quantite' => '9',
            'taille' => 'M',
            'motif' => '   ',
        ]);

        self::assertStringContainsString('Indiquez la raison', $client->followRedirect()->html());
        $this->em->clear();
        self::assertSame(3, $this->movementRepository()->find($mouvement->getId())?->getQuantite());
    }

    public function testUneTailleEtrangereALArticleEstRefusee(): void
    {
        $client = $this->loginAdmin();
        $chaussettes = (new StockItem())->setNom('Chaussettes')
            ->setKind(StockItemKind::EQUIPEMENT)
            ->setTypeVetement(StockItemVetementType::CHAUSSURES);
        $this->em->persist($chaussettes);
        $this->em->flush();
        $mouvement = $this->entree($chaussettes, 6, '40');

        $client->request('POST', '/admin/stock/mouvements/' . $mouvement->getId() . '/corriger', [
            '_token' => $this->jeton($client, $mouvement->getId()),
            'quantite' => '6',
            'taille' => 'XL',
            'motif' => 'Test',
        ]);

        self::assertStringContainsString('ne correspond pas', $client->followRedirect()->html());
        $this->em->clear();
        self::assertSame('40', $this->movementRepository()->find($mouvement->getId())?->getTaille());
    }

    /**
     * Une dotation remise à un joueur et une réception de commande ont un écran dédié : les
     * corriger ici désynchroniserait le besoin ou la commande.
     */
    public function testUnMouvementNonManuelNOffrePasDeCorrection(): void
    {
        $client = $this->loginAdmin();
        $maillot = $this->maillot();

        $dotation = (new StockMovement())
            ->setItem($maillot)
            ->setQuantite(1)
            ->setType(StockMovementType::SORTIE)
            ->setSource(StockMovementSource::DOTATION)
            ->setTaille('M');
        $this->em->persist($dotation);
        $this->em->flush();

        $html = $client->request('GET', '/admin/stock/mouvements')->html();
        self::assertStringNotContainsString('correct_stock_movement_' . $dotation->getId(), $html);

        // Et le jeton d'un mouvement manuel voisin ne vaut pas pour celui-ci : la requête
        // est écartée avant même d'atteindre le service (CsrfFailureListener renvoie
        // l'utilisateur d'où il vient).
        $manuel = $this->entree($maillot, 2, 'M');
        $client->request('POST', '/admin/stock/mouvements/' . $dotation->getId() . '/corriger', [
            '_token' => $this->jeton($client, $manuel->getId()),
            'quantite' => '2',
            'motif' => 'Tentative',
        ]);

        $this->em->clear();
        self::assertSame(1, $this->movementRepository()->find($dotation->getId())?->getQuantite());
        self::assertCount(0, $this->movementRepository()->find($dotation->getId())?->getCorrections() ?? []);
    }

    private function movementRepository(): StockMovementRepository
    {
        return self::getContainer()->get(StockMovementRepository::class);
    }

    private function maillot(): StockItem
    {
        $item = (new StockItem())->setNom('Maillot')
            ->setKind(StockItemKind::EQUIPEMENT)
            ->setTypeVetement(StockItemVetementType::HAUT);

        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function entree(StockItem $item, int $quantite, ?string $taille): StockMovement
    {
        $mouvement = (new StockMovement())
            ->setItem($item)
            ->setQuantite($quantite)
            ->setType(StockMovementType::ENTREE)
            ->setSource(StockMovementSource::MANUEL)
            ->setTaille($taille);

        $this->em->persist($mouvement);
        $this->em->flush();

        return $mouvement;
    }

    /**
     * Jeton lu dans la charge utile que la ligne passe à la modale — le parseur DOM de PHP
     * écarte les attributs `@click`, on lit donc le HTML brut.
     */
    private function jeton(KernelBrowser $client, int $mouvementId): string
    {
        $client->request('GET', '/admin/stock/mouvements');
        $html = (string) $client->getResponse()->getContent();
        $chemin = '/admin/stock/mouvements/' . $mouvementId . '/corriger';

        preg_match_all('/@click="ouvrir\((.*?)\)"/s', $html, $trouvees);
        foreach ($trouvees[1] as $brut) {
            $charge = json_decode(html_entity_decode($brut, \ENT_QUOTES | \ENT_HTML5), true);
            if (is_array($charge) && ($charge['action'] ?? null) === $chemin) {
                return (string) $charge['token'];
            }
        }

        self::fail('Aucun bouton de correction ne pointe vers ' . $chemin . '.');
    }

    private function loginAdmin(): KernelBrowser
    {
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-correction@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}
