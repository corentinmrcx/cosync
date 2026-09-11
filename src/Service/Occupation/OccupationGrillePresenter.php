<?php declare(strict_types=1);

namespace App\Service\Occupation;

use App\DTO\Occupation\GrilleOccupation;
use App\Entity\CreneauOccupation;
use App\Entity\Season;
use App\Repository\CreneauOccupationRepository;

/**
 * Met en forme la semaine type. N'écrit rien.
 *
 * L'écran d'administration et le PDF de la mairie passent tous deux par ici : c'est ce qui
 * garantit qu'ils montrent la même grille.
 */
final class OccupationGrillePresenter
{
    public function __construct(
        private readonly CreneauOccupationRepository $creneauRepo,
        private readonly OccupationGrilleGeometrie $geometrie,
    ) {}

    /**
     * La semaine type, à l'échelle de la journée d'un complexe : 8h–22h, toujours.
     *
     * La même pour l'écran et pour le document, et c'est un choix. À l'écran, une échelle
     * qui se rétracterait sur les seuls créneaux posés n'offrirait plus de place où en
     * glisser un nouveau — et sur une grille vide, il n'y aurait nulle part où commencer.
     * Sur le papier, elle vaut parce que la feuille remise à la mairie doit montrer ce que
     * l'admin a composé : deux échelles différentes donneraient deux dessins différents de
     * la même semaine.
     */
    public function grille(Season $season): GrilleOccupation
    {
        $creneaux = $this->creneauRepo->findParSaison($season);

        return $this->geometrie->grille($creneaux, $this->geometrie->plageEcran($creneaux));
    }

    /**
     * Les créneaux dont l'équipe manque — supprimée en cours de saison, ou sans équivalent
     * à la reprise. L'écran de génération l'annonce **avant** d'imprimer : découvrir un
     * « Équipe à compléter » sur le document remis à la mairie est un aller-retour qu'un
     * compteur évite.
     *
     * @return list<CreneauOccupation>
     */
    public function creneauxIncomplets(Season $season): array
    {
        return array_values(array_filter(
            $this->creneauRepo->findParSaison($season),
            static fn (CreneauOccupation $c): bool => $c->getEquipe() === null,
        ));
    }

    /**
     * Total d'heures réservées dans la semaine, par terrain — le premier chiffre que la
     * mairie regarde, et celui qui dit à l'admin si sa grille est plausible.
     *
     * @return array<string, float> nom du terrain => heures, dans l'ordre du référentiel
     */
    public function heuresParTerrain(Season $season): array
    {
        $totaux = [];
        $rangs = [];

        foreach ($this->creneauRepo->findParSaison($season) as $creneau) {
            $nom = $creneau->getEspace()->getNom();
            $totaux[$nom] = ($totaux[$nom] ?? 0) + $creneau->duree();
            $rangs[$nom] = $creneau->getEspace()->getOrdre();
        }

        // L'ordre du référentiel, pas celui du premier créneau rencontré : la légende du
        // document doit lister les terrains comme le club les range, terrain d'honneur en
        // tête, sinon deux éditions successives n'ont pas le même pied de page.
        uksort($totaux, static fn (string $a, string $b): int => [$rangs[$a], $a] <=> [$rangs[$b], $b]);

        return array_map(static fn (int $minutes): float => round($minutes / 60, 2), $totaux);
    }
}
