<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Repository\DetenteurRepository;
use App\Repository\DirigeantRepository;

/**
 * Rapproche le registre des clés (hors saison) de l'effectif (par saison), dans les
 * deux sens.
 *
 * C'est ce rapprochement qui permet de dire « cette personne détient une clé mais
 * n'est plus dirigeante cette année ». Il se fait sur le numéro de licence, seul
 * identifiant stable, avec le nom en repli pour les personnes qui n'en ont pas.
 */
final class DetenteurEffectifResolver
{
    public function __construct(
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly DetenteurRepository $detenteurRepo,
    ) {}

    /** Le détenteur correspondant à ce dirigeant, s'il figure au registre des clés. */
    public function detenteurDe(Dirigeant $dirigeant): ?Detenteur
    {
        $numLicence = $dirigeant->getNumLicence();

        if ($numLicence !== null && $numLicence !== '') {
            $parLicence = $this->detenteurRepo->findByNumLicence($numLicence);

            if ($parLicence !== null) {
                return $parLicence;
            }
        }

        return $this->detenteurRepo->findByNomPrenom($dirigeant->getNom(), $dirigeant->getPrenom());
    }

    /**
     * @param Detenteur[] $detenteurs
     *
     * @return array<int, Dirigeant> dirigeant de la saison, indexé par id de détenteur ;
     *                               absent pour les détenteurs hors effectif
     */
    public function pourSaison(Season $season, array $detenteurs): array
    {
        $parLicence = [];
        $parNom = [];

        foreach ($this->dirigeantRepo->findBySeason($season) as $dirigeant) {
            $numLicence = $dirigeant->getNumLicence();

            if ($numLicence !== null && $numLicence !== '') {
                $parLicence[$numLicence] = $dirigeant;
            }

            $parNom[mb_strtolower($dirigeant->getNom()) . '|' . mb_strtolower($dirigeant->getPrenom())] = $dirigeant;
        }

        $rattachements = [];

        foreach ($detenteurs as $detenteur) {
            $numLicence = $detenteur->getNumLicence();
            $dirigeant = ($numLicence !== null && $numLicence !== '')
                ? ($parLicence[$numLicence] ?? null)
                : null;

            $dirigeant ??= $parNom[$detenteur->cleNomPrenom()] ?? null;

            if ($dirigeant !== null) {
                $rattachements[$detenteur->getId()] = $dirigeant;
            }
        }

        return $rattachements;
    }
}
