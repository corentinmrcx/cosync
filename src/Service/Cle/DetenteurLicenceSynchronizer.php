<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\Entity\Detenteur;
use App\Entity\Season;
use App\Repository\DetenteurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Repose le numéro de licence sur les fiches du registre qui en manquent.
 *
 * DetenteurService::depuisDirigeant() le recopie à l'entrée au registre, mais il ne
 * peut recopier que ce qui existe à cet instant : un dirigeant créé à la main avant
 * le premier import FootClubs n'a pas encore de licence, et une fois la personne
 * inscrite au registre le sélecteur la propose en « detenteur:N » — plus aucun appel
 * ne repasse par le dirigeant, donc plus aucune occasion de rattraper le champ.
 *
 * Le registre retombait alors sur le nom, seul repli disponible, que le prochain
 * import pouvait réécrire à l'orthographe FootClubs. Cette passe rend au registre son
 * identifiant stable et le sort de cette dépendance.
 *
 * Idempotente et sans effet visible : elle ne remplit que les fiches vides, jamais ne
 * corrige ni n'écrase une licence déjà posée. Elle est jouée avant chaque lecture du
 * registre, comme DotationEcoulementAllocator l'est avant le suivi des dotations, et
 * pour la même raison : elle dépend d'un effectif qui bouge.
 */
final class DetenteurLicenceSynchronizer
{
    public function __construct(
        private readonly DetenteurRepository $detenteurRepo,
        private readonly DetenteurEffectifResolver $effectifResolver,
        private readonly EntityManagerInterface $em,
    ) {}

    public function pourSaison(Season $season): void
    {
        $tous = $this->detenteurRepo->findAllOrdered();

        $sansLicence = array_values(array_filter(
            $tous,
            static fn (Detenteur $detenteur): bool => ($detenteur->getNumLicence() ?? '') === '',
        ));

        if ($sansLicence === []) {
            return;
        }

        // Une licence ne désigne qu'une personne : si elle est déjà portée par une
        // fiche du registre, la reposer ailleurs créerait deux détenteurs que
        // findByNumLicence() ne saurait plus départager.
        $dejaPrises = [];

        foreach ($tous as $detenteur) {
            $numLicence = $detenteur->getNumLicence();

            if ($numLicence !== null && $numLicence !== '') {
                $dejaPrises[$numLicence] = true;
            }
        }

        $effectif = $this->effectifResolver->pourSaison($season, $sansLicence);
        $aEcrire = false;

        foreach ($sansLicence as $detenteur) {
            $numLicence = ($effectif[$detenteur->getId()] ?? null)?->getNumLicence();

            if ($numLicence === null || $numLicence === '' || isset($dejaPrises[$numLicence])) {
                continue;
            }

            $detenteur->setNumLicence($numLicence);
            $dejaPrises[$numLicence] = true;
            $aEcrire = true;
        }

        if ($aEcrire) {
            $this->em->flush();
        }
    }
}
