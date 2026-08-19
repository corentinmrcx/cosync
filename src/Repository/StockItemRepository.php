<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockItem>
 */
class StockItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockItem::class);
    }

    /** @return StockItem[] Catalogue actif (actif = true), trié par catégorie puis nom. */
    public function findAllOrdered(bool $includeArchived = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.category', 'c')
            ->addSelect('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('i.nom', 'ASC');

        if (!$includeArchived) {
            $qb->andWhere('i.actif = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Articles écoulés à la place de celui-ci, dans l'ordre où ils doivent partir : les
     * archivés d'abord — un article sorti du catalogue est justement celui dont il faut se
     * débarrasser — puis par nom, pour que l'arbitrage soit reproductible d'un écran à l'autre.
     *
     * @return list<StockItem>
     */
    public function findSubstituts(StockItem $officiel): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.remplaceArticle = :officiel')
            ->setParameter('officiel', $officiel)
            ->orderBy('i.actif', 'ASC')
            ->addOrderBy('i.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countSubstituts(StockItem $officiel): int
    {
        return $this->count(['remplaceArticle' => $officiel]);
    }

    /**
     * Tous les articles principaux qui ont au moins un ancien stock à écouler — l'index de
     * l'écran de correspondances. Les archivés en font partie : le club peut sortir du
     * catalogue un article qu'il continue d'écouler.
     *
     * @return list<StockItem>
     */
    public function findArticlesAvecSubstituts(): array
    {
        return $this->createQueryBuilder('i')
            ->where('EXISTS (SELECT 1 FROM ' . StockItem::class . ' s WHERE s.remplaceArticle = i)')
            ->orderBy('i.nom', 'ASC')
            ->addOrderBy('i.marque', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Articles qu'on peut écouler à la place de celui-ci.
     *
     * Miroir de {@see findCiblesEcoulementPossibles()}, qui répond à la même question depuis
     * l'autre bout. Les exclusions traduisent les règles de
     * {@see \App\Service\Stock\StockItemService::appliquerEcoulement()} : pas soi-même, pas
     * une cible d'écoulement (un article ne peut pas être remplacé et remplaçant), et le même
     * type de vêtement — sans quoi la dotation lirait la taille du bas pour servir un haut.
     *
     * Un article déjà écoulé ailleurs est écarté : il figure dans sa propre correspondance,
     * où on le retire d'abord. Le laisser proposé le déplacerait en silence d'une transition
     * à l'autre.
     *
     * `$principal` à null rend le vivier complet, tous types confondus — le formulaire de
     * création, qui ne connaît pas encore son principal, restreint côté client et le serveur
     * refait le contrôle.
     *
     * @return list<StockItem>
     */
    public function findArticlesEcoulablesVers(?StockItem $principal): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.actif = true')
            ->andWhere('i.kind = :equipement')
            ->andWhere('i.remplaceArticle IS NULL')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . StockItem::class . ' s WHERE s.remplaceArticle = i)')
            ->setParameter('equipement', StockItemKind::EQUIPEMENT)
            ->orderBy('i.nom', 'ASC')
            ->addOrderBy('i.marque', 'ASC');

        if ($principal === null) {
            return $qb->getQuery()->getResult();
        }

        $qb->andWhere('i.id != :principal')->setParameter('principal', $principal->getId());

        // « Aucun type » est un type comme un autre — un bonnet s'écoule à la place d'un
        // bonnet. Comparer avec `=` ne l'aurait jamais rendu : en SQL, NULL n'égale rien.
        if (($type = $principal->getTypeVetement()) === null) {
            $qb->andWhere('i.typeVetement IS NULL');
        } else {
            $qb->andWhere('i.typeVetement = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Articles que celui-ci peut déclarer remplacer. On écarte l'article lui-même, les
     * articles d'écoulement (pas de chaîne : Nike → Adidas → ERIMA) et, si l'article est
     * déjà remplacé par d'autres, tous les candidats — il est une cible, il ne peut pas
     * devenir substitut à son tour.
     *
     * @return list<StockItem>
     */
    public function findCiblesEcoulementPossibles(?StockItem $item): array
    {
        if ($item !== null && $this->countSubstituts($item) > 0) {
            return [];
        }

        $qb = $this->createQueryBuilder('i')
            ->where('i.actif = true')
            ->andWhere('i.kind = :equipement')
            ->andWhere('i.remplaceArticle IS NULL')
            ->setParameter('equipement', StockItemKind::EQUIPEMENT)
            ->orderBy('i.nom', 'ASC');

        if ($item !== null) {
            $qb->andWhere('i.id != :self')->setParameter('self', $item->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /** @return string[] */
    public function findDistinctMarques(): array
    {
        return $this->findDistinctValues('marque');
    }

    /** @return string[] */
    public function findDistinctTaillesByKind(StockItemKind $kind): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.taille')
            ->distinct()
            ->where('i.taille IS NOT NULL')
            ->andWhere('i.kind = :kind')
            ->andWhere('i.actif = true')
            ->setParameter('kind', $kind)
            ->orderBy('i.taille', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'taille');
    }

    /** @return string[] */
    public function findDistinctCouleurs(): array
    {
        return $this->findDistinctValues('couleur');
    }

    /** @return string[] */
    private function findDistinctValues(string $field): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select("i.{$field}")
            ->distinct()
            ->where("i.{$field} IS NOT NULL")
            ->andWhere('i.actif = true')
            ->orderBy("i.{$field}", 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, $field);
    }
}
