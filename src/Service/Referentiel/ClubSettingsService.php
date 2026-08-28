<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\ClubSettings;
use App\Repository\ClubSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
        private readonly SignatureCachetStorage $signatures,
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

    /**
     * Remplace la signature scannée du signataire. L'ancienne image est effacée du
     * disque : c'est un paraphe, il n'y a aucune raison d'en garder des copies dormantes.
     */
    public function remplacerSignature(UploadedFile $fichier): void
    {
        $settings = $this->get();
        $ancienne = $settings->getSignatureCachetFichier();

        $settings->setSignatureCachetFichier($this->signatures->enregistrer($fichier));
        $this->em->flush();

        $this->signatures->supprimer($ancienne);
    }

    public function supprimerSignature(): void
    {
        $settings = $this->get();
        $fichier = $settings->getSignatureCachetFichier();

        $settings->setSignatureCachetFichier(null);
        $this->em->flush();

        $this->signatures->supprimer($fichier);
    }
}
