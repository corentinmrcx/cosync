<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\Category;
use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Fournisseur;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\Team;
use App\Enum\CommandeStatut;
use App\Enum\DotationBesoinStatut;
use App\Enum\LicenceStatus;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests d'intégration stock/dotations : conteneur réel + base réelle.
 * Chaque test tourne dans une transaction annulée (dama/doctrine-test-bundle).
 */
abstract class StockIntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function service(string $class): object
    {
        return self::getContainer()->get($class);
    }

    /* ── Fabriques ── */

    protected function makeSeason(string $label = '2025-2026'): Season
    {
        $season = (new Season())->setLabel($label)->setBaseCosts(['jeunes' => 85, 'seniors' => 120]);
        $this->em->persist($season);
        return $season;
    }

    protected function makeCategory(string $code = 'SENIOR'): Category
    {
        $cat = (new Category())->setCode($code)->setLabel($code)->setIsEcoleFoot(false);
        $this->em->persist($cat);
        return $cat;
    }

    protected function makeTeam(Season $season, string $name = 'Séniors 1'): Team
    {
        $team = (new Team())->setName($name)->setSeason($season);
        $this->em->persist($team);
        return $team;
    }

    protected function makeFournisseur(string $nom = 'Sport2000'): Fournisseur
    {
        $f = (new Fournisseur())->setNom($nom);
        $this->em->persist($f);
        return $f;
    }

    protected function makeItem(
        string $nom = 'Veste',
        ?StockItemVetementType $type = StockItemVetementType::HAUT,
        ?Fournisseur $fournisseur = null,
    ): StockItem {
        $item = (new StockItem())->setNom($nom)->setTypeVetement($type)->setFournisseur($fournisseur);
        $this->em->persist($item);
        return $item;
    }

    protected function makeModele(Season $season, string $nom = 'Dotation sénior'): DotationModele
    {
        $m = (new DotationModele())->setSeason($season)->setNom($nom);
        $this->em->persist($m);
        return $m;
    }

    protected function addLigne(
        DotationModele $modele,
        StockItem $item,
        int $qte = 1,
        ?string $groupeChoix = null,
    ): DotationModeleLigne {
        $ligne = (new DotationModeleLigne())->setStockItem($item)->setQuantite($qte)->setGroupeChoix($groupeChoix);
        $modele->addLigne($ligne);
        $this->em->persist($ligne);
        return $ligne;
    }

    protected function affecterCategorie(Season $s, DotationModele $m, Category $c): DotationAffectation
    {
        $a = (new DotationAffectation())->setSeason($s)->setModele($m)->setCategory($c);
        $this->em->persist($a);
        return $a;
    }

    protected function affecterLicencie(Season $s, DotationModele $m, Licencie $l): DotationAffectation
    {
        $a = (new DotationAffectation())->setSeason($s)->setModele($m)->setLicencie($l);
        $this->em->persist($a);
        return $a;
    }

    protected function makeLicencie(
        Season $season,
        Category $cat,
        ?Team $team = null,
        string $tailleHaut = 'L',
        LicenceStatus $status = LicenceStatus::VALIDATED,
    ): Licencie {
        static $n = 0;
        ++$n;
        $licencie = (new Licencie())
            ->setNom('TEST' . $n)
            ->setPrenom('Joueur' . $n)
            ->setDateNaissance(new \DateTimeImmutable('2000-01-01'))
            ->setCategory($cat)
            ->setSeason($season);
        if ($team !== null) {
            $licencie->setTeam($team);
        }
        $this->em->persist($licencie);

        $dossier = (new DossierClub())->setLicencie($licencie);
        $dossier->setTailleHaut($tailleHaut);
        $dossier->setStatus($status);
        $this->em->persist($dossier);

        return $licencie;
    }

    protected function makeBesoin(
        Season $season,
        StockItem $item,
        ?string $taille,
        int $qte = 1,
        DotationBesoinStatut $statut = DotationBesoinStatut::A_DONNER,
    ): DotationBesoin {
        $b = (new DotationBesoin())
            ->setSeason($season)
            ->setStockItem($item)
            ->setTaille($taille)
            ->setQuantite($qte)
            ->setStatut($statut);
        $this->em->persist($b);
        return $b;
    }

    protected function makeMovement(StockItem $item, int $qte, StockMovementType $type, ?string $taille): void
    {
        self::getContainer()->get(StockService::class)->recordMovement(
            $item, $qte, $type, StockMovementSource::MANUEL, null, $type === StockMovementType::REBUT ? 'test' : null, taille: $taille,
        );
    }

    protected function makeCommandeEnAttente(
        Season $season,
        StockItem $item,
        ?string $taille,
        int $qte,
        ?Fournisseur $fournisseur = null,
    ): CommandeLigne {
        $commande = (new Commande())->setSeason($season)->setFournisseur($fournisseur)->setStatut(CommandeStatut::COMMANDEE);
        $ligne = (new CommandeLigne())->setStockItem($item)->setTaille($taille)->setQuantite($qte);
        $commande->addLigne($ligne);
        $this->em->persist($commande);
        $this->em->persist($ligne);
        return $ligne;
    }

    /** Vide l'unité de travail et recharge une entité depuis la base (relations hydratées). */
    protected function reload(object $entity): object
    {
        $class = $entity::class;
        $id = $entity instanceof Licencie || $entity instanceof Dirigeant ? $entity->getUuid() : $entity->getId();
        $this->em->flush();
        $this->em->clear();
        return $this->em->find($class, $id);
    }
}
