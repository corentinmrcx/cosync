<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dirigeant;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationBesoinStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DotationBesoin>
 */
class DotationBesoinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DotationBesoin::class);
    }

    /** @return DotationBesoin[] */
    public function findBySeason(Season $season): array
    {
        // COALESCE n'est pas autorisé directement dans ORDER BY en DQL → on l'expose en
        // champ HIDDEN (exclu de l'hydratation) pour pouvoir trier dessus.
        return $this->createQueryBuilder('b')
            ->leftJoin('b.stockItem', 'i')->addSelect('i')
            ->leftJoin('b.articleEcoulement', 'e')->addSelect('e')
            ->leftJoin('b.articleRemis', 'r')->addSelect('r')
            ->leftJoin('b.licencie', 'l')->addSelect('l')
            ->leftJoin('b.dirigeant', 'd')->addSelect('d')
            ->addSelect('COALESCE(l.nom, d.nom) AS HIDDEN personneNom')
            ->addSelect('COALESCE(l.prenom, d.prenom) AS HIDDEN personnePrenom')
            ->where('b.season = :season')
            ->setParameter('season', $season)
            // Regroupe les lignes d'une même personne (licencié ou dirigeant), puis ordre stable.
            ->orderBy('personneNom', 'ASC')
            ->addOrderBy('personnePrenom', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Besoins **pas encore remis** de la saison — « à donner » et « préparés » —, article et
     * fournisseur préchargés.
     *
     * Les préparés en font partie et ce n'est pas un détail : leur sortie de stock n'est pas
     * faite, l'armoire les compte encore. Les écarter ferait croire aux achats qu'ils sont
     * servis alors que le stock les compte disponibles, et le club sous-commanderait d'autant.
     *
     * @return DotationBesoin[]
     */
    public function findNonRemisBySeason(Season $season): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.stockItem', 'i')->addSelect('i')
            ->leftJoin('i.fournisseur', 'f')->addSelect('f')
            ->leftJoin('b.articleEcoulement', 'e')->addSelect('e')
            ->leftJoin('e.fournisseur', 'ef')->addSelect('ef')
            ->where('b.season = :season')
            ->andWhere('b.statut != :remis')
            ->setParameter('season', $season)
            ->setParameter('remis', DotationBesoinStatut::DONNE)
            ->getQuery()
            ->getResult();
    }

    /** Besoins servis par cet article d'écoulement — garde de suppression du catalogue. */
    public function countByArticleEcoulement(StockItem $item): int
    {
        return $this->count(['articleEcoulement' => $item]);
    }

    /**
     * Besoins qu'on a remis avec cet article à la place de celui du kit — garde de suppression
     * du catalogue. Sans elle, la colonne étant en `SET NULL`, supprimer l'article effacerait
     * en silence la trace de ce qui a été donné.
     */
    public function countByArticleRemis(StockItem $item): int
    {
        return $this->count(['articleRemis' => $item]);
    }

    /**
     * Besoins de la personne pour SA saison. Le filtre est indispensable : ces listes
     * alimentent le recalcul, qui supprime les besoins « à donner » devenus caducs — sans
     * lui, un recalcul détruirait les besoins des autres saisons.
     *
     * @return DotationBesoin[]
     */
    public function findForLicencie(Licencie $licencie): array
    {
        return $this->findBy(['licencie' => $licencie, 'season' => $licencie->getSeason()]);
    }

    /** @return DotationBesoin[] */
    public function findForDirigeant(Dirigeant $dirigeant): array
    {
        return $this->findBy(['dirigeant' => $dirigeant, 'season' => $dirigeant->getSeason()]);
    }

    /** Besoins de dotation attendus dans cette taille — garde du référentiel. */
    public function countByTaille(string $libelle): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.taille = :libelle')
            ->setParameter('libelle', $libelle)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<string> Tailles attendues par des besoins de dotation. */
    public function findDistinctTailles(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('DISTINCT b.taille AS taille')
            ->where('b.taille IS NOT NULL')
            ->getQuery()
            ->getScalarResult();

        return array_map('strval', array_column($rows, 'taille'));
    }
}
