<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\DirigeantRole;
use App\Enum\LicenceStatus;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
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

    private function makeLicencie(?Team $team): Licencie
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
        $dossier->setTailleHaut('L')->setStatus(LicenceStatus::VALIDATED);
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
