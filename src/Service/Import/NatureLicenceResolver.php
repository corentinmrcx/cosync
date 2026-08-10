<?php declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Season;
use App\Enum\NatureLicence;
use App\Repository\LicencieRepository;
use App\Repository\SeasonRepository;

/**
 * Détermine si une licence est une première licence au club ou un renouvellement.
 *
 * Source principale : la colonne « Nature » de l'export FootClubs, seule fiable dès la
 * première saison d'utilisation de CoSync. L'historique des saisons ne sert qu'en renfort :
 * il ne vaut que si la saison précédente a été importée dans l'outil.
 */
final class NatureLicenceResolver
{
    /** @var array<int, bool> Cache par saison — un import parcourt des centaines de lignes. */
    private array $historiqueCache = [];

    public function __construct(
        private readonly LicencieRepository $licencieRepository,
        private readonly SeasonRepository $seasonRepository,
    ) {}

    /**
     * Nature retenue pour un licencié importé.
     *
     * Retrouver le numéro dans une saison antérieure prouve un renouvellement. L'inverse
     * ne prouve rien : la saison précédente peut n'avoir jamais été importée, ou l'avoir
     * été partiellement. On ne déduit donc JAMAIS « nouveau » d'une absence — ce serait
     * priver de son choix de dotation un licencié qui y a droit. Null = inconnu, état
     * légitime que l'admin peut corriger.
     */
    public function resolve(?string $rawNature, string $numLicence, Season $season): ?NatureLicence
    {
        $nature = NatureLicence::fromExport($rawNature);
        if ($nature !== null) {
            return $nature;
        }

        if (!$this->historiqueExploitable($season)) {
            return null;
        }

        return $this->licencieRepository->existsInEarlierSeason($numLicence, $season)
            ? NatureLicence::RENOUVELLEMENT
            : null;
    }

    /**
     * Vrai si l'export et l'historique se contredisent — signalé dans le rapport d'import
     * sans rien changer : c'est la colonne « Nature » qui fait foi.
     */
    public function contredit(NatureLicence $nature, string $numLicence, Season $season): bool
    {
        if (!$this->historiqueExploitable($season)) {
            return false;
        }

        return $this->licencieRepository->existsInEarlierSeason($numLicence, $season)
            !== ($nature === NatureLicence::RENOUVELLEMENT);
    }

    /** L'historique ne dit quelque chose que s'il existe au moins une saison antérieure. */
    private function historiqueExploitable(Season $season): bool
    {
        return $this->historiqueCache[$season->getId()] ??= $this->seasonRepository->hasEarlierThan($season);
    }
}
