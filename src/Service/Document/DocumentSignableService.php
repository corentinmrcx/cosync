<?php declare(strict_types=1);

namespace App\Service\Document;

use App\DTO\DocumentSignableData;
use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Enum\DocumentCible;
use App\Repository\DirigeantRepository;
use App\Repository\DocumentSignableRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Création et mise à jour des documents signables depuis l'administration.
 *
 * Le code, le chemin d'archivage Drive et le préfixe de fichier sont dérivés du titre
 * à la création puis figés : renommer un document ne doit pas déplacer les PDF déjà
 * archivés ni rendre introuvables ceux en attente d'upload.
 */
final class DocumentSignableService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DirigeantRepository $dirigeantRepo,
    ) {}

    public function creer(DocumentSignableData $data, Season $season): DocumentSignable
    {
        $code = $this->slug($data->titre);

        if ($code === '') {
            throw new \DomainException('Le titre du document ne permet pas de générer un identifiant.');
        }

        if ($this->documentRepo->existsByCode($season, $code)) {
            throw new \DomainException(sprintf('Un document intitulé « %s » existe déjà pour cette saison.', $data->titre));
        }

        $document = (new DocumentSignable())
            ->setSeason($season)
            ->setCode($code)
            ->setDriveSegments(['Documents signés', $this->nomDossierDrive($data->titre)])
            ->setFilePrefix($this->filePrefix($code))
            ->setSortOrder($this->documentRepo->nextSortOrder($season, $data->cible));

        $this->hydrater($document, $data);

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function mettreAJour(DocumentSignable $document, DocumentSignableData $data): void
    {
        $this->hydrater($document, $data);
        $this->em->flush();
    }

    public function basculerActivation(DocumentSignable $document): void
    {
        $document->setActif(!$document->isActif());
        $this->em->flush();
    }

    public function supprimer(DocumentSignable $document): void
    {
        $this->em->remove($document);
        $this->em->flush();
    }

    private function hydrater(DocumentSignable $document, DocumentSignableData $data): void
    {
        $document
            ->setTitre($data->titre)
            ->setLibelle($data->libelle)
            ->setContenuHtml($data->contenuHtml)
            ->setCible($data->cible)
            ->setActif($data->actif);

        // Le ciblage ne concerne que les dirigeants : un document destiné aux licenciés
        // s'adresse à toute la saison, garder un ciblage résiduel serait trompeur.
        if ($data->cible === DocumentCible::LICENCIE) {
            $document->setRoles([])->clearDirigeants();

            return;
        }

        $document->setRoles($data->roles)->clearDirigeants();

        foreach ($data->dirigeants as $uuid) {
            $dirigeant = $this->dirigeantRepo->findByUuid(Uuid::fromString($uuid));

            if ($dirigeant !== null && $dirigeant->getSeason()->getId() === $document->getSeason()->getId()) {
                $document->addDirigeant($dirigeant);
            }
        }
    }

    /** Identifiant stable dérivé du titre : « Charte communication » → « charte_communication ». */
    private function slug(string $titre): string
    {
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titre) ?: $titre;
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $slug));

        return substr(trim($slug, '_'), 0, 60);
    }

    /** Préfixe des PDF archivés : « charte_communication » → « CHARTE_COMMUNICATION ». */
    private function filePrefix(string $code): string
    {
        return substr(strtoupper($code), 0, 30);
    }

    /** Nom du sous-dossier Drive : le titre lisible, sans slash qui créerait un niveau parasite. */
    private function nomDossierDrive(string $titre): string
    {
        return trim((string) preg_replace('#\s*/\s*#', ' - ', $titre));
    }
}
