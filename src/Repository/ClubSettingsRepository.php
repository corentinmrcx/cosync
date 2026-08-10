<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClubSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClubSettings>
 */
class ClubSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubSettings::class);
    }

    /** La table ne contient qu'une ligne ; null tant que la migration ne l'a pas créée. */
    public function findSingle(): ?ClubSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
