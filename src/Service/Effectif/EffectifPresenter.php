<?php declare(strict_types=1);

namespace App\Service\Effectif;

use App\DTO\Effectif\EffectifTableauDeBord;
use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Repository\DirigeantRepository;
use App\Repository\DocumentSignableRepository;
use App\Repository\LicencieRepository;
use App\Service\Document\DocumentSignableService;

/** Compteurs du hub Effectif. Lecture seule. */
final class EffectifPresenter
{
    public function __construct(
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DocumentSignableRepository $documentRepository,
        private readonly DocumentSignableService $documentService,
    ) {}

    public function getTableauDeBord(Season $season): EffectifTableauDeBord
    {
        return new EffectifTableauDeBord(
            $this->licencieRepository->countWithFilters($season),
            $this->dirigeantRepository->countBySeason($season),
            $this->licencieRepository->countWithFilters($season, status: LicenceStatus::IMPORTED),
            $this->licencieRepository->countWithFilters($season, status: LicenceStatus::LINK_SENT),
            $this->licencieRepository->countWithFilters($season, status: LicenceStatus::FORM_COMPLETED),
            $this->compterSignaturesEnAttente($season),
        );
    }

    private function compterSignaturesEnAttente(Season $season): int
    {
        $actifs = array_filter(
            $this->documentRepository->findBySeason($season),
            static fn (DocumentSignable $document): bool => $document->isActif(),
        );

        $enAttente = 0;
        foreach ($this->documentService->statistiques($actifs, $season) as $statistiques) {
            $enAttente += $statistiques->enAttente;
        }

        return $enAttente;
    }
}
