<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\GrilleTaille;
use App\Entity\GrilleTailleValeur;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\Taille;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DirigeantRole;
use App\Enum\LicenceStatus;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Enum\TailleType;
use App\Repository\TailleRepository;
use App\Service\Referentiel\TailleReferentiel;
use App\Service\Stock\StockMovementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran de suivi des dotations : ce que l'admin doit pouvoir lire et corriger avant de
 * préparer les kits.
 */
final class DotationSuiviScreenTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;

    /**
     * L'écran sépare l'encadrement des équipes de joueurs. Un dirigeant rattaché aux Séniors
     * s'affichait au milieu d'eux avec un kit qui n'est pas le leur.
     */
    public function testLesDirigeantsOntLeurPropreGroupe(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $seniors = (new Team())->setName('Séniors')->setSeason($this->season);
        $this->em->persist($seniors);

        $joueur = $this->makeLicencie($seniors);
        $this->makeBesoin($this->makeItem('Maillot'))->setLicencie($joueur);

        $dirigeant = (new Dirigeant())
            ->setNom('MARCOUX')->setPrenom('Olivier')
            ->setSeason($this->season)->setRole(DirigeantRole::DIRIGEANT)
            ->setTeam($seniors);
        $this->em->persist($dirigeant);
        $this->makeBesoin($this->makeItem('Polo'))->setDirigeant($dirigeant);

        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Séniors', 'Dirigeants'],
            $crawler->filter('.dot-card-title')->each(static fn ($n): string => $n->text()),
            'L\'encadrement forme son propre groupe, en fin de liste.',
        );
    }

    /**
     * Le licencié n'a pas pu remplir son formulaire : le besoin est floqué mais sans texte.
     * L'écran doit le signaler et offrir la saisie, faute de quoi il ne reste que la base.
     */
    public function testUnFlocageSansTexteEstSignaleEtSaisissable(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $tshirt = $this->makeItem('T-shirt');
        $modele = (new DotationModele())->setSeason($this->season)->setNom('Kit sénior');
        $ligne = (new DotationModeleLigne())
            ->setStockItem($tshirt)->setQuantite(1)
            ->setPersonnalisationRequise(true)->setPersonnalisationLabel('Nom à floquer au dos');
        $modele->addLigne($ligne);
        $this->em->persist($modele);
        $this->em->persist($ligne);
        $this->em->persist((new DotationAffectation())->setSeason($this->season)->setModele($modele));

        $licencie = $this->makeLicencie(null);
        $besoin = $this->makeBesoin($tshirt)->setLicencie($licencie);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/suivi');
        $html = $crawler->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Flocage à renseigner', $html);
        self::assertStringContainsString('placeholder="Nom à floquer au dos"', $html);

        $token = $crawler->filter('form[action$="/personnalisation"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/dotations/besoins/' . $besoin->getId() . '/personnalisation', [
            '_token' => $token,
            'personnalisation' => 'Coco',
        ]);

        self::assertResponseRedirects('/admin/dotations/suivi');
        $this->em->clear();

        self::assertSame(
            'Coco',
            $this->em->getRepository(DotationBesoin::class)->find($besoin->getId())->getPersonnalisation(),
        );
    }

    /** Un article que le kit ne floque pas n'affiche aucune saisie : ce serait du bruit. */
    public function testUnArticleNonFloqueNOffreAucuneSaisie(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $chaussettes = $this->makeItem('Chaussettes');
        $modele = (new DotationModele())->setSeason($this->season)->setNom('Kit sénior');
        $ligne = (new DotationModeleLigne())->setStockItem($chaussettes)->setQuantite(1);
        $modele->addLigne($ligne);
        $this->em->persist($modele);
        $this->em->persist($ligne);
        $this->em->persist((new DotationAffectation())->setSeason($this->season)->setModele($modele));

        $this->makeBesoin($chaussettes)->setLicencie($this->makeLicencie(null));
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Flocage', $crawler->html());
    }

    /**
     * Celui qui prépare une remise doit savoir s'il va chercher l'article ou s'il attend un
     * colis — et, quand un stock en cours d'écoulement reprend la ligne, quelle paire prendre.
     * L'écran disait « à remettre » sans jamais le dire.
     */
    public function testLaLigneAnnonceSaProvenanceEtLArticleAPrendre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes');
        $erima->setMarque('Erima')->setCouleur('Noir');
        $nike = $this->makeItem('Chaussettes');
        $nike->setMarque('Nike')->setCouleur('Noir')->setRemplaceArticle($erima);

        // Deux personnes, une seule paire d'ancien stock : la première est servie, la
        // seconde retombe sur l'article du kit, que rien ne couvre.
        $this->makeBesoin($erima)->setLicencie($this->makeLicencie(null));
        $this->makeBesoin($erima)->setLicencie($this->makeLicencie(null));
        $this->em->flush();

        self::getContainer()->get(StockMovementService::class)->recordMovement(
            $nike, 1, StockMovementType::ENTREE, StockMovementSource::MANUEL, null, null, taille: 'L',
        );

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();

        $pastilles = $crawler->filter('.dot-provenance');
        self::assertSame(['Stock', 'À commander'], $pastilles->each(static fn ($n): string => $n->text()));
        self::assertSame(
            'À prendre dans le stock : Chaussettes · Nike · Noir — taille L',
            $pastilles->eq(0)->attr('title'),
            'L\'infobulle désigne l\'article servi, pas celui du kit.',
        );
        self::assertSame(
            ['Nike · Noir'],
            $crawler->filter('.dot-ecoulement-badge')->each(static fn ($n): string => trim($n->text())),
            'Ce qu\'on donne ressort ; la ligne retombée sur le kit n\'a pas de badge.',
        );
        self::assertSame('au lieu de Erima · Noir', trim($crawler->filter('.dot-ecoulement-mention')->text()));
    }

    /**
     * L'écran ne propose qu'une chose par ligne : l'étape suivante. Tant que le sac n'est pas
     * fait, « Marquer remis » n'a rien à y faire — le club a commencé à préparer les dotations
     * bien avant de les remettre, et rien ne le disait. Le geste qui revient en arrière, lui,
     * n'est pas un troisième bouton : il ferme le menu « ⋯ » de la ligne, sous un filet.
     */
    public function testLaLigneProposeUneSeuleEtapeEtSonRetour(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $besoin = $this->makeBesoin($this->makeItem('Maillot'))->setLicencie($this->makeLicencie(null));
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Préparer'],
            $crawler->filter('.dot-table-action button')->each(static fn ($n): string => trim($n->text())),
            'Un seul bouton dans la colonne d\'actions, celui de l\'étape du moment.',
        );
        self::assertCount(0, $crawler->filter('.dot-table-menu .fiche-menu-item-danger'), 'Rien de franchi, rien à défaire.');

        $token = $crawler->filter('form[action$="/preparer"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/dotations/besoins/' . $besoin->getId() . '/preparer', ['_token' => $token]);

        self::assertResponseRedirects('/admin/dotations/suivi');
        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertStringContainsString('Préparé', $crawler->html(), 'Le badge dit où en est la ligne.');
        self::assertSame(
            ['Marquer remis'],
            $crawler->filter('.dot-table-action button')->each(static fn ($n): string => trim($n->text())),
            'L\'étape suivante prend la place, elle ne s\'ajoute pas à côté.',
        );
        self::assertSame(
            ['Défaire la préparation'],
            $crawler->filter('.dot-table-menu .fiche-menu-item-danger')->each(static fn ($n): string => trim($n->text())),
            'Le verrou a sa sortie, en bas du menu de la ligne.',
        );
        self::assertStringNotContainsString(
            'dot-provenance',
            $crawler->html(),
            'Le sac est fait : d\'où vient l\'article ne regarde plus personne.',
        );
    }

    /**
     * Le « 37 » de l'Erima couvre les pointures 37 à 40. Affiché seul, il faisait passer un
     * joueur qui chausse du 39 pour un 37 — qu'on croyait alors servi par le carton Nike 34-38.
     * Et « Chaussettes » sans marque ne disait pas quel carton ouvrir.
     */
    public function testLaTailleDitLaPlageDuCartonEtLaPointureDeclaree(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $grille = (new GrilleTaille())->setNom('Chaussettes Erima')->setType(TailleType::POINTURE);
        $valeur = (new GrilleTailleValeur())->setCible($this->taille('37'));
        foreach (['37', '38', '39', '40'] as $pointure) {
            $valeur->addCouverture($this->taille($pointure));
        }
        $grille->addValeur($valeur);
        $this->em->persist($grille);
        $this->em->persist($valeur);

        $chaussettes = $this->makeItem('Chaussettes')
            ->setMarque('Erima')->setCouleur('Noir')
            ->setTypeVetement(StockItemVetementType::CHAUSSURES)
            ->setGrilleTaille($grille);
        $this->makeBesoin($chaussettes)->setTaille('37')->setLicencie($this->makeLicencie(null, pointure: '39'));
        $this->em->flush();
        // Relu depuis la base : l'écran recalcule la taille depuis le dossier du licencié.
        $this->em->clear();

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertSame('37-40', $crawler->filter('.dot-item-taille')->text(), 'L\'étiquette du carton, pas le libellé du stock.');
        self::assertSame('pointure 39', $crawler->filter('.dot-taille-declaree')->text());
        self::assertSame('Erima · Noir', $crawler->filter('.dot-item-details')->text());
        self::assertSame('Automatique (taille du dossier)', $crawler->filter('select[name="taille"] option')->first()->text());
        self::assertCount(
            0,
            $crawler->filter('select[name="taille"] option[selected]'),
            'Une taille qui suit le dossier ouvre sur « Automatique » : valider ne la verrouille pas.',
        );
        self::assertCount(0, $crawler->filter('.dot-taille-lock'));
    }

    /**
     * Un « 34 » seul ne dit pas s'il est l'étiquette du carton ou la pointure du joueur : pour un
     * article chaussé, la pointure s'écrit même quand elle porte le même nombre. Un vêtement, lui,
     * ne répète pas « M » sous « M ».
     */
    public function testLaPointureSEcritMemeQuandElleEgaleLEtiquette(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $chaussettes = $this->makeItem('Chaussettes')->setTypeVetement(StockItemVetementType::CHAUSSURES);
        $this->makeBesoin($chaussettes)->setTaille('34')->setLicencie($this->makeLicencie(null, pointure: '34'));
        $this->makeBesoin($this->makeItem('Maillot'))->setLicencie($this->makeLicencie(null));
        $this->em->flush();
        $this->em->clear();

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['pointure 34'],
            $crawler->filter('.dot-taille-declaree')->each(static fn ($n): string => $n->text()),
            'La pointure s\'écrit sous le « 34 » ; le maillot en L déclaré L n\'ajoute rien.',
        );
    }

    /** Le verrou d'une taille corrigée à la main se voyait nulle part : « 16 ans » ne bougeait plus. */
    public function testUneTailleFixeeALaMainPorteSonVerrou(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeBesoin($this->makeItem('Maillot'))
            ->setTaille('XS')->setTailleManuelle(true)
            ->setLicencie($this->makeLicencie(null));
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.dot-taille-lock'));
        self::assertSame('taille L', $crawler->filter('.dot-taille-declaree')->text(), 'Le dossier dit autre chose : on le montre.');
        self::assertSame('XS', $crawler->filter('select[name="taille"] option[selected]')->attr('value'));
    }

    /**
     * Les corrections n'ont pas d'ordre : elles vont au menu « ⋯ » de la ligne, plus en crayons
     * contre chaque valeur — resté seul sous une marque, un crayon ne disait plus ce qu'il
     * corrigeait. La saisie, elle, reste dans la cellule de la valeur.
     */
    public function testLesCorrectionsDeLaLigneSontDansSonMenu(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $this->makeBesoin($this->makeItem('Maillot'))->setLicencie($this->makeLicencie(null));
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/dotations/suivi');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Corriger la taille'],
            $crawler->filter('.dot-table-menu .fiche-menu-item')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertCount(1, $crawler->filter('.dot-taille-edit form[action$="/taille"]'), 'La saisie reste dans la cellule de la taille.');
        self::assertCount(0, $crawler->filter('.dot-taille-pen, .dot-option-pen'), 'Plus de crayon contre les valeurs.');
    }

    private function taille(string $libelle): Taille
    {
        $existante = self::getContainer()->get(TailleRepository::class)->findOneByLibelle(TailleType::POINTURE, $libelle);
        if ($existante !== null) {
            return $existante;
        }

        $taille = (new Taille())->setLibelle($libelle)->setType(TailleType::POINTURE);
        $this->em->persist($taille);
        $this->em->flush();
        self::getContainer()->get(TailleReferentiel::class)->oublier();

        return $taille;
    }

    private function makeItem(string $nom): StockItem
    {
        $item = (new StockItem())->setNom($nom)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->persist($item);

        return $item;
    }

    private function makeBesoin(StockItem $item): DotationBesoin
    {
        $besoin = (new DotationBesoin())->setSeason($this->season)->setStockItem($item)->setTaille('L');
        $this->em->persist($besoin);

        return $besoin;
    }

    private function makeLicencie(?Team $team, ?string $pointure = null): Licencie
    {
        static $n = 0;
        ++$n;

        $category = (new Category())->setCode('SENIOR' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);
        $this->em->persist($category);

        $licencie = (new Licencie())
            ->setNom('DUPONT' . $n)->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2000-01-01'))
            ->setCategory($category)->setSeason($this->season);
        if ($team !== null) {
            $licencie->setTeam($team);
        }
        $this->em->persist($licencie);

        $dossier = (new DossierClub())->setLicencie($licencie);
        $dossier->setTailleHaut('L')->setPointure($pointure)->setStatus(LicenceStatus::VALIDATED);
        $this->em->persist($dossier);

        return $licencie;
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $user = (new User())->setSuperAdmin(true)->setEmail('admin-suivi-dotations@example.com')->setPassword('x');

        $this->em->persist($this->season);
        $this->em->persist($user);
        $this->em->flush();

        $user->setSelectedSeason($this->season);
        $this->em->flush();

        $client->loginUser($user);
    }
}
