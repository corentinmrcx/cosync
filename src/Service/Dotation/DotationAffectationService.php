<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationAffectationData;
use App\Entity\Dirigeant;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Enum\DotationCibleType;
use App\Repository\CategoryRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DotationAffectationRepository;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Attribution d'un kit de dotation à une ou plusieurs populations.
 */
final class DotationAffectationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DotationAffectationRepository $affectationRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TeamRepository $teamRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
    ) {}

    /**
     * Attribue le kit à toutes les cibles retenues d'un coup. Les cibles qui le reçoivent déjà
     * sont ignorées sans bruit : l'écran les affiche verrouillées, deux admins simultanés ne
     * doivent pas pour autant créer de doublon.
     *
     * @return list<DotationAffectation> les affectations réellement créées
     *
     * @throws \DomainException si aucune cible n'est retenue ou si l'une d'elles n'existe pas
     */
    public function creer(DotationModele $modele, DotationAffectationData $data, Season $season): array
    {
        $deja = $this->cibleIdsDejaAttribuees($modele, $data->cibleType);

        if ($data->cibleType->estDefaut()) {
            if ($deja !== []) {
                throw new \DomainException('Ce kit est déjà attribué par défaut à toute la saison.');
            }

            $defaut = (new DotationAffectation())->setSeason($season)->setModele($modele);
            $this->em->persist($defaut);
            $this->em->flush();

            return [$defaut];
        }

        if ($data->cibleIds === []) {
            throw new \DomainException('Sélectionnez au moins un destinataire.');
        }

        $creees = [];

        foreach (array_diff(array_unique($data->cibleIds), $deja) as $cibleId) {
            $affectation = (new DotationAffectation())->setSeason($season)->setModele($modele);
            $this->appliquerCible($affectation, $data->cibleType, $cibleId);

            // priorite() vaut 0 quand aucune cible n'a pu être posée : un id qui ne correspond
            // à rien produirait sinon une affectation par défaut silencieuse. On refuse tout le
            // lot avant le flush, plutôt que d'en enregistrer une partie.
            if ($affectation->priorite() === 0) {
                throw new \DomainException('Cible invalide pour cette affectation.');
            }

            $this->em->persist($affectation);
            $creees[] = $affectation;
        }

        if ($creees !== []) {
            $this->em->flush();
        }

        return $creees;
    }

    public function supprimer(DotationAffectation $affectation): void
    {
        $this->em->remove($affectation);
        $this->em->flush();
    }

    /** @return list<string> les cibles de ce type que le kit dote déjà */
    private function cibleIdsDejaAttribuees(DotationModele $modele, DotationCibleType $type): array
    {
        $deja = [];

        foreach ($this->affectationRepository->findByModele($modele) as $affectation) {
            if ($affectation->cibleType() === $type) {
                $deja[] = (string) $affectation->cibleId();
            }
        }

        return $deja;
    }

    private function appliquerCible(DotationAffectation $affectation, DotationCibleType $type, string $cibleId): void
    {
        match ($type) {
            DotationCibleType::CATEGORY => $affectation->setCategory($this->categoryRepository->find((int) $cibleId)),
            DotationCibleType::TEAM => $affectation->setTeam($this->teamRepository->find((int) $cibleId)),
            DotationCibleType::LICENCIE => $affectation->setLicencie($this->licencieParUuid($cibleId)),
            DotationCibleType::DIRIGEANT => $affectation->setDirigeant($this->dirigeantParUuid($cibleId)),
            DotationCibleType::ROLE => $affectation->setRole(DirigeantRole::tryFrom($cibleId)),
            DotationCibleType::DEFAUT => null,
        };
    }

    private function licencieParUuid(string $uuid): ?Licencie
    {
        return Uuid::isValid($uuid) ? $this->licencieRepository->findByUuid(Uuid::fromString($uuid)) : null;
    }

    private function dirigeantParUuid(string $uuid): ?Dirigeant
    {
        return Uuid::isValid($uuid) ? $this->dirigeantRepository->findByUuid(Uuid::fromString($uuid)) : null;
    }
}
