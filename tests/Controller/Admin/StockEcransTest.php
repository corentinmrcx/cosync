<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Season;
use App\Entity\StockCategory;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\StockCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les écrans de stock rendus avec des données réelles.
 *
 * AccesAdminTest les visite déjà, mais sur une base vide : les boucles n'y sont jamais
 * parcourues, donc rien de ce qui est affiché article par article n'est vérifié.
 */
final class StockEcransTest extends WebTestCase
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

    public function testLeTableauDeGestionAfficheChaqueArticleAvecSaVentilationParTaille(): void
    {
        $client = $this->loginAdmin();

        $veste = $this->makeItem('Veste de survêtement', seuil: 5);
        $this->entree($veste, 3, 'L');
        $this->entree($veste, 2, 'M');

        $html = $client->request('GET', '/admin/stock/gestion')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Veste de survêtement', $html);
        self::assertStringContainsString('Stock bas', $html, '5 en stock pour un seuil de 5.');
    }

    /**
     * La modale de mouvement est unique : c'est le serveur qui dépose, article par article,
     * la liste de tailles qu'elle proposera. Une paire de chaussettes se réassortit en
     * pointures, une bouteille sans taille du tout.
     */
    public function testChaqueArticleExposeLesTaillesQuiLuiCorrespondent(): void
    {
        $client = $this->loginAdmin();

        $chaussettes = $this->makeItem('Chaussettes');
        $chaussettes->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::CHAUSSURES);
        $maillot = $this->makeItem('Maillot');
        $maillot->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::HAUT);
        $boisson = $this->makeItem('Coca-Cola');
        $boisson->setKind(StockItemKind::EPICERIE)->setTaille('33cl');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/gestion');
        self::assertResponseIsSuccessful();

        $options = static fn (StockItem $item): array => json_decode(
            $crawler->filter('#tailles-' . $item->getId())->attr('value') ?? '',
            true,
        )['options'];

        self::assertSame(['24', '25'], \array_slice($options($chaussettes), 0, 2));
        self::assertNotContains('XL', $options($chaussettes));
        self::assertContains('XL', $options($maillot));
        self::assertSame([], $options($boisson), 'L\'épicerie porte sa contenance, pas une taille.');
    }

    public function testUnArticleSansMouvementEstSignaleEnRupture(): void
    {
        $client = $this->loginAdmin();
        $this->makeItem('Chasuble', seuil: 2);

        $html = $client->request('GET', '/admin/stock/gestion')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Rupture', $html);
    }

    public function testLeTableauDeBordCompteSeparementRupturesEtStockBas(): void
    {
        $client = $this->loginAdmin();

        $enRupture = $this->makeItem('Chasuble', seuil: 2);
        $stockBas = $this->makeItem('Ballon', seuil: 5);
        $this->entree($stockBas, 4, null);
        $suffisant = $this->makeItem('Plot', seuil: 1);
        $this->entree($suffisant, 50, null);

        $html = $client->request('GET', '/admin/stock')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Chasuble', $html);
        self::assertStringContainsString('Ballon', $html);
        self::assertStringNotContainsString('Plot', $html, 'Un article au-dessus de son seuil ne remonte pas en alerte.');
        self::assertSame($enRupture->getAlertSeuil(), 2);
    }

    public function testLaFeuilleDInventaireEstUnPdf(): void
    {
        $client = $this->loginAdmin();

        $veste = $this->makeItem('Veste de survêtement', seuil: 5);
        $this->entree($veste, 3, 'L');
        $this->makeItem('Article sans taille ni mouvement');

        $client->request('GET', '/admin/stock/inventaire.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    /**
     * Les deux référentiels du stock partagent désormais la même mise en page que le reste de
     * la section : liste en carte, bouton de création en haut à droite. Ils n'étaient rendus
     * par aucun test — seule leur redirection vers /login l'était.
     */
    public function testLesReferentielsSuiventLaMemeMiseEnPage(): void
    {
        $client = $this->loginAdmin();
        $this->makeItem('Chaussettes');

        foreach ([
            '/admin/stock/categories' => 'Nouvelle catégorie',
            '/admin/stock/fournisseurs' => 'Nouveau fournisseur',
        ] as $url => $libelleBouton) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
            self::assertCount(1, $crawler->filter('.dot-page'), $url . ' doit utiliser la mise en page commune.');
            self::assertCount(1, $crawler->filter('.dot-header-actions .btn-primary'), $url . ' doit porter son bouton en haut à droite.');
            self::assertStringContainsString($libelleBouton, $crawler->filter('.dot-header-actions')->html());
        }
    }

    /** Les filtres de l'historique passent par la barre dépliante partagée avec Licenciés. */
    public function testLHistoriqueUtiliseLaBarreDeFiltresPartagee(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Maillot');
        $this->entree($item, 4, 'L');

        $crawler = $client->request('GET', '/admin/stock/mouvements?type=entree');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.list-tb-filters'));
        self::assertCount(1, $crawler->filter('.list-tb-search-input'), 'La recherche est à côté du bouton Filtres.');
        self::assertCount(2, $crawler->filter('.list-tb-dropdown input[type="date"]'), 'Les bornes de dates sont dans le dépliant.');
        self::assertStringContainsString('1', $crawler->filter('.list-tb-filters-count')->text());
    }

    /**
     * La recherche porte sur le nom de l'article et la note du mouvement, et ne compte pas
     * dans la pastille « Filtres » : elle a son propre champ.
     */
    public function testLaRechercheDeLHistoriquePorteSurLArticleEtLaNote(): void
    {
        $client = $this->loginAdmin();
        $this->entree($this->makeItem('Chasuble'), 10, null);
        $this->entree($this->makeItem('Ballon'), 5, null);

        $crawler = $client->request('GET', '/admin/stock/mouvements?search=chasu');

        self::assertResponseIsSuccessful();

        // Sur les lignes du tableau, pas sur la page : le sélecteur d'articles du dépliant
        // liste tout le catalogue, « Ballon » y figure quoi qu'il arrive.
        $articles = $crawler->filter('tbody .stock-mouvements-item-nom')->each(static fn ($n) => trim($n->text()));
        self::assertSame(['Chasuble'], $articles);

        self::assertCount(0, $crawler->filter('.list-tb-filters-count'), 'La recherche seule ne remplit pas la pastille.');
    }

    /**
     * L'ordre des catégories se règle au glisser-déposer : le formulaire de création n'a plus
     * de champ « ordre », et une nouvelle catégorie se place d'office en fin de liste.
     */
    public function testUneNouvelleCategorieSePlaceEnFinDeListe(): void
    {
        $client = $this->loginAdmin();
        $this->makeCategorie('Buvette', 0);
        $this->makeCategorie('Textile', 1);

        $crawler = $client->request('GET', '/admin/stock/categories');
        self::assertCount(0, $crawler->filter('input[name="stock_category[position]"]'), 'Plus de champ ordre à la main.');

        $formulaire = $crawler->filter('form[action="/admin/stock/categories/nouveau"]')->form();
        $formulaire['stock_category[name]'] = 'Ballons';
        $client->submit($formulaire);

        self::assertResponseRedirects('/admin/stock/categories');
        self::assertSame(['Buvette', 'Textile', 'Ballons'], $this->nomsDesCategories());
    }

    public function testLeGlisserDeposerEnregistreLeNouvelOrdre(): void
    {
        $client = $this->loginAdmin();
        $buvette = $this->makeCategorie('Buvette', 0);
        $textile = $this->makeCategorie('Textile', 1);
        $ballons = $this->makeCategorie('Ballons', 2);

        $client->request('POST', '/admin/stock/categories/reordonner', [
            '_token' => $this->jetonReorder($client),
            'ordre' => [$ballons->getId(), $buvette->getId(), $textile->getId()],
        ]);

        self::assertResponseRedirects('/admin/stock/categories');
        self::assertSame(['Ballons', 'Buvette', 'Textile'], $this->nomsDesCategories());
    }

    /**
     * Un onglet resté ouvert pendant qu'une catégorie était créée ailleurs renvoie une liste
     * incomplète. Celle qu'il ignore doit être reléguée à la suite, jamais perdue de vue.
     */
    public function testUneCategorieAbsenteDeLOrdreRecuEstRelegueeALaSuite(): void
    {
        $client = $this->loginAdmin();
        $buvette = $this->makeCategorie('Buvette', 0);
        $textile = $this->makeCategorie('Textile', 1);
        $this->makeCategorie('Ballons', 2);

        $client->request('POST', '/admin/stock/categories/reordonner', [
            '_token' => $this->jetonReorder($client),
            'ordre' => [$textile->getId(), $buvette->getId()],
        ]);

        self::assertSame(['Textile', 'Buvette', 'Ballons'], $this->nomsDesCategories());
    }

    /** Jeton repris du formulaire réellement rendu : le gestionnaire CSRF le stocke en session. */
    private function jetonReorder(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/stock/categories');
        $champ = $crawler->filter('form[action="/admin/stock/categories/reordonner"] input[name="_token"]');

        self::assertGreaterThan(0, $champ->count(), 'Formulaire de réordonnancement introuvable.');

        return (string) $champ->first()->attr('value');
    }

    /**
     * Quitter le formulaire article ramène là où l'enregistrement mène — la gestion du stock,
     * pas le tableau de bord : renoncer à une modification ne doit pas déplacer l'utilisateur.
     */
    public function testAnnulerLeFormulaireArticleRamenneALaGestion(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Chasuble');

        foreach (['/admin/stock/items/nouveau', '/admin/stock/items/' . $item->getId() . '/modifier'] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
            $annuler = $crawler->filter('.stock-items-actions a')->first();
            self::assertSame('/admin/stock/gestion', $annuler->attr('href'), $url);
        }
    }

    private function makeCategorie(string $nom, int $position): StockCategory
    {
        $category = (new StockCategory())->setName($nom)->setPosition($position);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /** @return string[] */
    private function nomsDesCategories(): array
    {
        $this->em->clear();

        return array_map(
            static fn (StockCategory $c) => $c->getName(),
            self::getContainer()->get(StockCategoryRepository::class)->findAllOrderedByPosition(),
        );
    }

    private function makeItem(string $nom, ?int $seuil = null): StockItem
    {
        $category = (new StockCategory())->setName('Équipement')->setPosition(1);
        $this->em->persist($category);

        $item = (new StockItem())->setNom($nom)->setCategory($category)->setAlertSeuil($seuil);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function entree(StockItem $item, int $quantite, ?string $taille): void
    {
        $mouvement = (new StockMovement())
            ->setItem($item)
            ->setQuantite($quantite)
            ->setType(StockMovementType::ENTREE)
            ->setSource(StockMovementSource::MANUEL)
            ->setTaille($taille);

        $this->em->persist($mouvement);
        $this->em->flush();
    }

    /**
     * Le stock physique ne porte aucun season_id : ses écrans doivent tenir debout même quand
     * la base ne contient encore aucune saison. Tant qu'ils exigeaient #[CurrentSeason], ce
     * cas les envoyait tous sur l'écran « aucune saison configurée ».
     */
    public function testLesEcransDeStockTiennentSansAucuneSaisonEnBase(): void
    {
        $this->makeItem('Ballon T5', seuil: 10);
        $client = $this->loginAdminSansAucuneSaison();

        foreach (['/admin/stock', '/admin/stock/gestion', '/admin/stock/mouvements'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('%s ne dépend d\'aucune saison.', $url));
        }
    }

    /**
     * Le « à commander » se calcule à partir des besoins de dotation de la saison : il n'a
     * rien à faire sur un écran commun à toutes les saisons.
     */
    public function testLeTableauDeBordStockNAfficheAucunCompteurDeSaison(): void
    {
        $client = $this->loginAdmin();

        $html = $client->request('GET', '/admin/stock')->html();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('À commander', $html);
        self::assertStringNotContainsString('en attente', $html);
    }

    private function loginAdmin(): KernelBrowser
    {
        $user = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }

    /**
     * SeasonContext retombe sur la saison la plus récente : pour qu'il rende vraiment null,
     * il ne doit rester aucune saison en base.
     */
    private function loginAdminSansAucuneSaison(): KernelBrowser
    {
        $user = (new User())->setEmail('admin-sans-saison@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');

        $this->em->persist($user);
        $this->em->remove($this->season);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}
