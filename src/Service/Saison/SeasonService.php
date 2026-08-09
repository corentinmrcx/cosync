<?php declare(strict_types=1);

namespace App\Service\Saison;

use App\Entity\Season;
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

    /**
     * Le libellé d'une saison est toujours « année-année suivante » : l'admin ne saisit que
     * l'année de début, le reste en découle.
     */
    public function anneeDeDebut(Season $season): int
    {
        return (int) explode('-', $season->getLabel())[0];
    }

    public function renommerParAnnee(Season $season, int $anneeDeDebut): void
    {
        $season->setLabel($anneeDeDebut . '-' . ($anneeDeDebut + 1));
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
