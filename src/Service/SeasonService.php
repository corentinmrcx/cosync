<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Enum\ReglementAudience;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SeasonService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeasonRepository $seasonRepo,
    ) {}

    public function create(Season $season): void
    {
        if ($this->seasonRepo->existsByLabel($season->getLabel())) {
            throw new \DomainException(sprintf('La saison "%s" existe déjà.', $season->getLabel()));
        }

        $this->em->persist($season);
        $this->em->flush();
    }

    public function update(Season $season): void
    {
        $this->em->flush();
    }

    /** Enregistre le texte du règlement de la saison pour un destinataire donné. */
    public function updateReglement(Season $season, ReglementAudience $audience, ?string $reglementText): void
    {
        match ($audience) {
            ReglementAudience::LICENCIE  => $season->setReglementText($reglementText),
            ReglementAudience::DIRIGEANT => $season->setReglementDirigeantText($reglementText),
        };

        $this->em->flush();
    }

    public function updateAttestationCleText(Season $season, ?string $attestationCleText): void
    {
        $season->setAttestationCleText($attestationCleText);
        $this->em->flush();
    }

    public function delete(Season $season): void
    {
        $this->em->remove($season);
        $this->em->flush();
    }
}
