<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\ClubSettings;
use App\Repository\ClubSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux réglages du club. La table ne porte qu'une ligne : elle est créée par la
 * migration, mais on la recrée à la volée si elle manque (base de test, purge) pour que
 * l'écran de configuration reste utilisable.
 */
final class ClubSettingsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClubSettingsRepository $repository,
    ) {}

    public function get(): ClubSettings
    {
        $settings = $this->repository->findSingle();
        if ($settings !== null) {
            return $settings;
        }

        $settings = new ClubSettings();
        $this->em->persist($settings);
        $this->em->flush();

        return $settings;
    }

    public function enregistrer(): void
    {
        $this->em->flush();
    }
}
