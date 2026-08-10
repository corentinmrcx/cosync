<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationAffectationData;
use App\Entity\Dirigeant;
use App\Entity\DotationAffectation;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Enum\DotationCibleType;
use App\Repository\CategoryRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DotationModeleRepository;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Attribution d'un kit de dotation à une population.
 */
final class DotationAffectationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DotationModeleRepository $modeleRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TeamRepository $teamRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
    ) {}

    /**
     * @throws \DomainException si le kit est introuvable ou si la cible désignée n'existe pas
     */
    public function creer(DotationAffectationData $data, Season $season): DotationAffectation
    {
        $modele = $this->modeleRepository->find($data->modeleId);
        if ($modele === null) {
            throw new \DomainException('Modèle introuvable.');
        }

        $affectation = (new DotationAffectation())->setSeason($season)->setModele($modele);
        $this->appliquerCible($affectation, $data);

        // priorite() vaut 0 quand aucune cible n'a pu être posée : un id qui ne correspond
        // à rien produirait sinon une affectation par défaut silencieuse.
        if (!$data->cibleType->estDefaut() && $affectation->priorite() === 0) {
            throw new \DomainException('Cible invalide pour cette affectation.');
        }

        $this->em->persist($affectation);
        $this->em->flush();

        return $affectation;
    }

    public function supprimer(DotationAffectation $affectation): void
    {
        $this->em->remove($affectation);
        $this->em->flush();
    }

    private function appliquerCible(DotationAffectation $affectation, DotationAffectationData $data): void
    {
        $cibleId = $data->cibleId;

        match ($data->cibleType) {
            DotationCibleType::CATEGORY => $affectation->setCategory($this->categoryRepository->find((int) $cibleId)),
            DotationCibleType::TEAM => $affectation->setTeam($this->teamRepository->find((int) $cibleId)),
            DotationCibleType::LICENCIE => $affectation->setLicencie($this->licencieParUuid($cibleId)),
            DotationCibleType::DIRIGEANT => $affectation->setDirigeant($this->dirigeantParUuid($cibleId)),
            DotationCibleType::ROLE => $affectation->setRole(DirigeantRole::tryFrom((string) $cibleId)),
            DotationCibleType::DEFAUT => null,
        };
    }

    private function licencieParUuid(?string $uuid): ?Licencie
    {
        return Uuid::isValid((string) $uuid) ? $this->licencieRepository->findByUuid(Uuid::fromString((string) $uuid)) : null;
    }

    private function dirigeantParUuid(?string $uuid): ?Dirigeant
    {
        return Uuid::isValid((string) $uuid) ? $this->dirigeantRepository->findByUuid(Uuid::fromString((string) $uuid)) : null;
    }
}
