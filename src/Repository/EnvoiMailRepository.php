<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dirigeant;
use App\Entity\EnvoiMail;
use App\Entity\Licencie;
use App\Enum\TypeMail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EnvoiMail> */
class EnvoiMailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvoiMail::class);
    }

    /**
     * Tous les mails reçus par un licencié, du plus ancien au plus récent — l'ordre de
     * l'historique de sa fiche.
     *
     * @return EnvoiMail[]
     */
    public function pourLicencie(Licencie $licencie): array
    {
        return $this->findBy(['licencie' => $licencie], ['sentAt' => 'ASC']);
    }

    /** @return EnvoiMail[] */
    public function pourDirigeant(Dirigeant $dirigeant): array
    {
        return $this->findBy(['dirigeant' => $dirigeant], ['sentAt' => 'ASC']);
    }

    /**
     * Date du dernier mail reçu, quel qu'en soit le type.
     *
     * C'est l'ancre de la relance automatique : en repartant du dernier contact plutôt que
     * de la date d'inscription, une relance passée à la main repousse mécaniquement celle
     * du robot. Sans quoi les deux partiraient à quelques heures d'écart.
     */
    public function dernierEnvoi(Licencie|Dirigeant $personne): ?\DateTimeImmutable
    {
        $envoi = $this->findOneBy(
            $personne instanceof Licencie ? ['licencie' => $personne] : ['dirigeant' => $personne],
            ['sentAt' => 'DESC'],
        );

        return $envoi?->getSentAt();
    }

    /**
     * Dernier contact de tout un lot, en une requête — les listes d'effectif en affichent
     * une colonne, et un findOneBy par ligne ferait cent requêtes.
     *
     * @param Licencie[] $licencies
     *
     * @return array<string, \DateTimeImmutable> uuid du licencié => date du dernier mail
     */
    public function dernierEnvoiParLicencie(array $licencies): array
    {
        if ($licencies === []) {
            return [];
        }

        $lignes = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.licencie) AS uuid', 'MAX(e.sentAt) AS dernier')
            ->where('e.licencie IN (:licencies)')
            ->setParameter('licencies', $licencies)
            ->groupBy('e.licencie')
            ->getQuery()
            ->getArrayResult();

        $parUuid = [];
        foreach ($lignes as $ligne) {
            $parUuid[(string) $ligne['uuid']] = new \DateTimeImmutable((string) $ligne['dernier']);
        }

        return $parUuid;
    }

    /**
     * Combien de mails d'un type donné cette personne a déjà reçus.
     *
     * Sert le plafond de relances : passé ce nombre, on cesse d'écrire et la personne se
     * rattrape au téléphone. Le décompte est de fait cloisonné par saison — `Licencie`
     * l'est déjà, une nouvelle saison crée une nouvelle fiche.
     *
     * @param TypeMail[] $types
     */
    public function compterEnvois(Licencie|Dirigeant $personne, array $types): int
    {
        if ($types === []) {
            return 0;
        }

        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.type IN (:types)')
            ->setParameter('types', $types);

        $personne instanceof Licencie
            ? $qb->andWhere('e.licencie = :personne')
            : $qb->andWhere('e.dirigeant = :personne');

        return (int) $qb->setParameter('personne', $personne)->getQuery()->getSingleScalarResult();
    }
}
